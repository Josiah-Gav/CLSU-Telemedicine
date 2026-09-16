<?php

namespace App\Services;

use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The one place that stores and serves a medical file.
 *
 * Every medical file in this application — a patient's intake attachments, an
 * in-consultation message attachment, and a prescription — is patient data and
 * must only ever be reachable through a controller action that authorizes
 * first. Before this class existed the same upload block was pasted into three
 * controllers and the same three-way "is it a URL?" branch into three download
 * actions, and they had already drifted: the messaging paths stored their
 * fallback on the private disk while the intake path stored it on the *public*
 * disk and handed back asset('storage/...'), which the web server serves
 * directly, before any PHP and therefore any authorization, runs.
 *
 * Two storage backends, one authorization model:
 *
 * - Cloudinary, uploaded as delivery type "authenticated". The plain delivery
 *   URL of such an asset returns 401, so possession of the URL is not access.
 *   Reading it needs a signature this application mints per request, and only
 *   after the calling controller has authorized.
 * - The local private disk (config/filesystems.php "message_attachments"),
 *   used when Cloudinary is unreachable. Its root sits outside
 *   storage/app/public, so the public/storage symlink cannot reach it, and it
 *   is declared serve => false so the framework registers no route to it.
 *
 * What is stored in the database is a *reference*, never a URL:
 *
 *   cloudinary:{resource_type}:{format}:{public_id}   e.g. cloudinary:image:png:telemed_consultations/ab12
 *                                                          cloudinary:raw::message_attachments/cd34.docx
 *   {disk-relative path}                              e.g. consultation-attachments/7/ef56.jpg
 *
 * public_id comes last because it is the only part that may contain a "/", so
 * a three-limit explode() parses the reference unambiguously. format is empty
 * for raw resources, whose public_id already carries the extension — that is
 * Cloudinary's own shape, confirmed against the installed SDK, not a guess.
 *
 * Rows written before this change hold an absolute http(s) URL. Those are
 * recognised and redirected to unchanged, so no backfill is required for the
 * application to keep working; they are simply not protected, which is why
 * they should be migrated (see attachments:move-to-private for the local case).
 */
class MedicalFileStorage
{
    /**
     * The private local disk. Deliberately the same disk the messaging
     * fallback already used, rather than a second one: one private root is one
     * thing to get right in a deployment, and the two file types stay separated
     * by directory exactly as they already were.
     */
    public const PRIVATE_DISK = 'message_attachments';

    /** Marks a stored value as a Cloudinary reference rather than a disk path. */
    private const CLOUDINARY_PREFIX = 'cloudinary:';

    /**
     * Cloudinary delivery type for every medical upload.
     *
     * "authenticated" (not the default "upload") is what makes the asset's own
     * URL useless without a signature. It is a constant rather than config
     * because a deployment that changed it would silently republish every
     * medical file to the open internet.
     */
    private const DELIVERY_TYPE = 'authenticated';

    /**
     * Store an uploaded medical file, preferring Cloudinary.
     *
     * Returns the reference to persist. Never throws for a storage failure: a
     * Cloudinary outage falls back to the private disk, because losing the
     * upload would lose clinical information the patient or physician has
     * already committed to.
     */
    public function store(UploadedFile $file, string $cloudinaryFolder, string $localDirectory): string
    {
        try {
            $result = Cloudinary::uploadApi()->upload($file->getRealPath(), [
                'folder' => $cloudinaryFolder,
                'resource_type' => 'auto',
                'type' => self::DELIVERY_TYPE,
                // Bounds a stalled upload so it cannot hold a PHP worker for the
                // SDK's 60-second default before the fallback below runs.
                // See config/cloudinary.php.
                'timeout' => config('cloudinary.upload_timeout'),
                'connect_timeout' => config('cloudinary.upload_timeout'),
            ]);

            $reference = $this->referenceFrom($result);

            if ($reference !== null) {
                return $reference;
            }

            // A 2xx response with no public_id would leave nothing to sign
            // later, so it is treated as a failed upload rather than persisted.
            Log::error('Cloudinary upload returned no usable public_id; storing on the private disk instead.');
        } catch (Throwable $uploadError) {
            Log::error('Cloudinary upload failed; storing on the private disk instead: '.$uploadError->getMessage());
        }

        return $file->store($localDirectory, self::PRIVATE_DISK);
    }

    /**
     * An HTTP response that delivers the referenced file to a caller the
     * controller has already authorized.
     *
     * $asAttachment picks the Content-Disposition. It is a parameter rather
     * than a fixed choice because the existing views render some of these
     * references inside an <img> for preview and others behind a download
     * link, and this change is not the place to alter that.
     */
    public function response(
        string $reference,
        ?string $downloadName = null,
        bool $asAttachment = true,
        array $headers = []
    ): Response {
        $name = $downloadName ?? basename($reference);

        if ($this->isCloudinaryReference($reference)) {
            return redirect()->away($this->signedUrl($reference, $asAttachment));
        }

        // Legacy rows written by the old public-disk fallback, as either
        // asset('storage/...') or a bare "/storage/..." path. Checked *before*
        // the remote-URL branch below and deliberately served from here rather
        // than redirected: redirecting would hand the caller the very public
        // URL this change exists to stop using. The bytes stay readable until
        // attachments:move-to-private relocates them, but every link the
        // application hands out now goes through an authorizing action.
        $legacyPublicPath = $this->legacyPublicDiskPath($reference);

        if ($legacyPublicPath !== null) {
            return $this->fromDisk('public', $legacyPublicPath, $name, $asAttachment, $headers);
        }

        // Legacy rows written before authenticated delivery: a public Cloudinary
        // URL that is already world-readable. Redirected as before — refusing it
        // here would break the page without making the file any less exposed.
        if ($this->isRemoteUrl($reference)) {
            return redirect()->away(
                $asAttachment ? $this->forceLegacyCloudinaryDownload($reference) : $reference
            );
        }

        return $this->fromDisk(self::PRIVATE_DISK, $reference, $name, $asAttachment, $headers);
    }

    private function fromDisk(
        string $disk,
        string $path,
        string $name,
        bool $asAttachment,
        array $headers
    ): Response {
        $filesystem = Storage::disk($disk);

        return $asAttachment
            ? $filesystem->download($path, $name, $headers)
            : $filesystem->response($path, $name, $headers);
    }

    /**
     * The public-disk-relative path behind a legacy "/storage/..." reference,
     * or null when this reference is not one.
     *
     * Existence on the public disk is part of the test, not just the shape: it
     * keeps a Cloudinary URL that merely happened to contain "/storage/" from
     * being misread as a local file, and lets a reference whose bytes have
     * already been migrated fall through to the private disk.
     */
    private function legacyPublicDiskPath(string $reference): ?string
    {
        $path = parse_url($reference, PHP_URL_PATH) ?: $reference;
        $marker = '/storage/';
        $position = strpos($path, $marker);

        if ($position === false) {
            return null;
        }

        $relative = ltrim(substr($path, $position + strlen($marker)), '/');

        return Storage::disk('public')->exists($relative) ? $relative : null;
    }

    /**
     * A short-lived, signed URL for one Cloudinary-hosted medical file.
     *
     * UploadApi::privateDownloadUrl() is the installed SDK's own mechanism for
     * this (cloudinary/cloudinary_php 3.1.3,
     * Api/Upload/ArchiveTrait::privateDownloadUrl). It signs public_id, format,
     * type and expires_at, so the URL stops working when it expires rather than
     * remaining valid forever the way a delivery URL's static s--…-- signature
     * does. Minted per request and never stored.
     */
    private function signedUrl(string $reference, bool $asAttachment): string
    {
        [$resourceType, $format, $publicId] = $this->parseCloudinaryReference($reference);

        return Cloudinary::uploadApi()->privateDownloadUrl($publicId, $format, [
            'resource_type' => $resourceType,
            'type' => self::DELIVERY_TYPE,
            'attachment' => $asAttachment,
            'expires_at' => now()->addSeconds($this->signedUrlTtl())->getTimestamp(),
        ]);
    }

    /**
     * The stable, opaque key one reference is addressed by in the
     * /consultations/{consultation}/attachments/{file} route.
     *
     * Deliberately basename()-shaped for all three reference forms, because
     * that is exactly what the existing views already compute with
     * basename($path) — so no view has to learn a new addressing scheme and no
     * route has to change. Matching is still a scan over the request's own
     * file_attachments array, so this key never has to be globally unique; it
     * only has to be derivable identically on both sides.
     */
    public function attachmentKey(string $reference): string
    {
        if ($this->isCloudinaryReference($reference)) {
            [, , $publicId] = $this->parseCloudinaryReference($reference);

            return basename($publicId);
        }

        if ($this->isRemoteUrl($reference)) {
            return basename(parse_url($reference, PHP_URL_PATH) ?: $reference);
        }

        return basename($reference);
    }

    /**
     * Remove a file this application stored, when it is being replaced.
     *
     * Only the private disk is cleaned up. A Cloudinary asset is deliberately
     * left in place: the previous implementation did the same for its http
     * rows, and deleting remote assets is a different decision (retention)
     * from the one this class exists to make (reachability).
     */
    public function delete(?string $reference): void
    {
        if ($reference === null || $reference === '') {
            return;
        }

        if ($this->isCloudinaryReference($reference) || $this->isRemoteUrl($reference)) {
            return;
        }

        Storage::disk(self::PRIVATE_DISK)->delete($reference);
    }

    public function isCloudinaryReference(string $reference): bool
    {
        return str_starts_with($reference, self::CLOUDINARY_PREFIX);
    }

    /** A legacy absolute URL stored before authenticated delivery. */
    public function isRemoteUrl(string $reference): bool
    {
        return str_starts_with($reference, 'http');
    }

    /**
     * Build the reference to persist from an upload response.
     *
     * Shapes confirmed against the live API with the installed SDK: an image
     * returns public_id without an extension plus a format; a raw asset
     * returns public_id *with* the extension and no format key at all.
     */
    private function referenceFrom(mixed $result): ?string
    {
        $publicId = $result['public_id'] ?? null;

        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $resourceType = (string) ($result['resource_type'] ?? 'image');
        $format = (string) ($result['format'] ?? '');

        return self::CLOUDINARY_PREFIX.$resourceType.':'.$format.':'.$publicId;
    }

    /**
     * @return array{0: string, 1: string, 2: string} resource type, format, public id
     */
    private function parseCloudinaryReference(string $reference): array
    {
        $parts = explode(':', substr($reference, strlen(self::CLOUDINARY_PREFIX)), 3);

        return [
            $parts[0] ?? 'image',
            $parts[1] ?? '',
            $parts[2] ?? '',
        ];
    }

    /**
     * Cloudinary serves a plain secure_url with Content-Disposition: inline.
     * Its fl_attachment delivery flag switches that to attachment; every
     * secure_url carries exactly one "/upload/" segment to insert it after.
     * Applies only to legacy public rows — a signed URL asks for the same thing
     * through its own attachment parameter.
     */
    private function forceLegacyCloudinaryDownload(string $url): string
    {
        return preg_replace('#/upload/#', '/upload/fl_attachment/', $url, 1) ?? $url;
    }

    private function signedUrlTtl(): int
    {
        return (int) config('cloudinary.signed_url_ttl');
    }
}
