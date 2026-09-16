<?php

namespace App\Console\Commands;

use App\Models\Consultation;
use App\Models\MessageAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Moves medical files that fell back to local storage off the public disk and
 * onto the private one.
 *
 * Two kinds of row, in two phases:
 *
 *  1. message_attachments.file_path - a disk-relative path that is identical on
 *     both disks, so only the bytes move and the row is left alone.
 *  2. consultation_requests.file_attachments - a JSON array whose legacy entries
 *     are asset('storage/...') URLs or bare "/storage/..." paths. Those carry a
 *     prefix that only makes sense for the public disk, so the entry itself is
 *     rewritten to the plain relative path as the bytes move.
 *
 * The class name predates phase 2 and is left alone deliberately; the command's
 * own signature, attachments:move-to-private, already covers both.
 *
 * Files on the public disk sit under storage/app/public, which the
 * public/storage symlink exposes to the web server, so they could be fetched
 * directly without passing ConsultationMessageController::downloadAttachment()
 * and its authorization check. Cloudinary-hosted attachments are unaffected —
 * their file_path is an absolute URL and is never touched here.
 *
 * The stored path is disk-relative and identical on both disks, so nothing in
 * the database has to change: only which disk the path resolves against, which
 * the controller now decides. That is also why this command is safe to re-run —
 * an attachment already on the private disk with no public copy left is simply
 * reported as already migrated.
 */
class MoveMessageAttachmentsToPrivateDisk extends Command
{
    protected $signature = 'attachments:move-to-private
                            {--dry-run : Report what would change without copying or deleting anything}';

    protected $description = 'Move locally-stored message attachments and consultation-request attachments from the public disk to the private medical disk';

    private const PUBLIC_DISK = 'public';

    private const PRIVATE_DISK = 'message_attachments';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run: no files will be copied or deleted.');
        }

        // Cloudinary rows store an absolute URL. This is the same test the
        // controller uses to decide how to serve an attachment, so the two can
        // never disagree about which rows are local.
        $localAttachments = MessageAttachment::query()
            ->where('file_path', 'not like', 'http%')
            ->orderBy('attachment_id')
            ->get();

        $cloudinaryCount = MessageAttachment::query()->where('file_path', 'like', 'http%')->count();

        $this->info(sprintf(
            'Found %d local attachment(s) to consider; %d Cloudinary attachment(s) will not be touched.',
            $localAttachments->count(),
            $cloudinaryCount
        ));

        $migrated = 0;
        $skipped = 0;
        $failed = 0;
        $orphanedPublicCopies = 0;

        foreach ($localAttachments as $attachment) {
            $path = (string) $attachment->file_path;
            $onPublic = Storage::disk(self::PUBLIC_DISK)->exists($path);
            $onPrivate = Storage::disk(self::PRIVATE_DISK)->exists($path);

            if (! $onPublic && $onPrivate) {
                $this->line("  [skip]    #{$attachment->attachment_id} already on the private disk");
                $skipped++;

                continue;
            }

            if (! $onPublic && ! $onPrivate) {
                // Never silently pass over a missing file: the row points at
                // bytes that are on neither disk and needs a human to look.
                $this->error("  [missing] #{$attachment->attachment_id} source file not found on either disk: {$path}");
                $failed++;

                continue;
            }

            if ($dryRun) {
                $this->line("  [would]   #{$attachment->attachment_id} copy to private disk and remove public copy");
                $migrated++;

                continue;
            }

            // Copy, then verify, before anything destructive happens. If this
            // command dies at any point before the delete below, the public
            // copy is still intact and the run can simply be repeated.
            try {
                $sourceSize = $this->copyToPrivateDisk($path);
            } catch (\Throwable $copyError) {
                $this->error("  [fail]    #{$attachment->attachment_id} copy failed: ".$copyError->getMessage());
                $failed++;

                continue;
            }

            if (! Storage::disk(self::PRIVATE_DISK)->exists($path)) {
                $this->error("  [fail]    #{$attachment->attachment_id} private copy missing after write; public copy left in place");
                $failed++;

                continue;
            }

            $destinationSize = Storage::disk(self::PRIVATE_DISK)->size($path);

            if ($destinationSize !== $sourceSize) {
                $this->error(sprintf(
                    '  [fail]    #%d size mismatch (source %d bytes, copy %d bytes); public copy left in place',
                    $attachment->attachment_id,
                    $sourceSize,
                    $destinationSize
                ));
                $failed++;

                continue;
            }

            // The stored path is disk-relative and identical on both disks, so
            // there is nothing to update on the row. It is re-checked here so a
            // future change to the path shape cannot slip through unnoticed.
            if ((string) $attachment->file_path !== $path) {
                $attachment->forceFill(['file_path' => $path])->save();
            }

            // Only now, with a verified private copy and a database row that
            // already points at it, is the exposed public copy removed.
            if (! Storage::disk(self::PUBLIC_DISK)->delete($path)) {
                $this->warn("  [partial] #{$attachment->attachment_id} migrated, but the public copy could not be deleted and is STILL WEB-ACCESSIBLE: {$path}");
                $orphanedPublicCopies++;
                $migrated++;

                continue;
            }

            $this->line("  [ok]      #{$attachment->attachment_id} migrated ({$sourceSize} bytes)");
            $migrated++;
        }

        $consultationResult = $this->migrateConsultationAttachments($dryRun);
        $migrated += $consultationResult['migrated'];
        $skipped += $consultationResult['skipped'];
        $failed += $consultationResult['failed'];
        $orphanedPublicCopies += $consultationResult['orphaned'];

        $this->newLine();
        $this->info(sprintf(
            'Done. migrated=%d skipped=%d failed=%d cloudinary_untouched=%d',
            $migrated,
            $skipped,
            $failed,
            $cloudinaryCount
        ));

        if ($orphanedPublicCopies > 0) {
            $this->error(sprintf(
                '%d file(s) remain readable from the public disk and must be removed manually.',
                $orphanedPublicCopies
            ));
        }

        return ($failed > 0 || $orphanedPublicCopies > 0) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Phase 2: consultation_requests.file_attachments.
     *
     * Unlike a message attachment, the stored value is not disk-relative — it is
     * an asset('storage/...') URL or a "/storage/..." path written by the old
     * public-disk fallback in ConsultationController::store(). That prefix is
     * meaningful only for the public disk, so it is stripped and the row is
     * rewritten as the bytes move; MedicalFileStorage then resolves the entry
     * against the private disk like any other.
     *
     * Cloudinary references and legacy remote URLs are left untouched, exactly
     * as phase 1 leaves its own Cloudinary rows alone.
     *
     * @return array{migrated: int, skipped: int, failed: int, orphaned: int}
     */
    private function migrateConsultationAttachments(bool $dryRun): array
    {
        $result = ['migrated' => 0, 'skipped' => 0, 'failed' => 0, 'orphaned' => 0];

        $consultations = Consultation::query()
            ->whereNotNull('file_attachments')
            ->orderBy('request_id')
            ->get();

        $this->newLine();
        $this->info(sprintf('Checking %d consultation request(s) for local attachments.', $consultations->count()));

        foreach ($consultations as $consultation) {
            $entries = $consultation->file_attachments ?? [];

            if (! is_array($entries) || $entries === []) {
                continue;
            }

            $rewritten = $entries;
            $rowChanged = false;

            foreach ($entries as $index => $entry) {
                $entry = (string) $entry;

                // Already a reference, or a remote URL with no /storage/ segment.
                $pathPortion = parse_url($entry, PHP_URL_PATH) ?: $entry;
                $marker = strpos($pathPortion, '/storage/');

                if (str_starts_with($entry, 'cloudinary:') || $marker === false) {
                    continue;
                }

                $relative = ltrim(substr($pathPortion, $marker + strlen('/storage/')), '/');
                $label = "#{$consultation->request_id}[{$index}]";

                $onPublic = Storage::disk(self::PUBLIC_DISK)->exists($relative);
                $onPrivate = Storage::disk(self::PRIVATE_DISK)->exists($relative);

                if (! $onPublic && $onPrivate) {
                    // Bytes already moved by an earlier run that died before the
                    // row was saved; finish the job by rewriting the entry.
                    $rewritten[$index] = $relative;
                    $rowChanged = true;
                    $result['skipped']++;
                    $this->line("  [skip]    {$label} already on the private disk");

                    continue;
                }

                if (! $onPublic && ! $onPrivate) {
                    $this->error("  [missing] {$label} source file not found on either disk: {$relative}");
                    $result['failed']++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("  [would]   {$label} copy to private disk, rewrite entry, remove public copy");
                    $result['migrated']++;

                    continue;
                }

                try {
                    $sourceSize = $this->copyToPrivateDisk($relative);
                } catch (\Throwable $copyError) {
                    $this->error("  [fail]    {$label} copy failed: ".$copyError->getMessage());
                    $result['failed']++;

                    continue;
                }

                if (! Storage::disk(self::PRIVATE_DISK)->exists($relative)
                    || Storage::disk(self::PRIVATE_DISK)->size($relative) !== $sourceSize) {
                    $this->error("  [fail]    {$label} private copy missing or wrong size; public copy left in place");
                    $result['failed']++;

                    continue;
                }

                $rewritten[$index] = $relative;
                $rowChanged = true;
                $result['migrated']++;

                // Deleted only after the verified copy exists. The row is saved
                // below before the loop ends, so a crash here leaves an entry
                // pointing at a public path whose bytes are already private —
                // which a re-run resolves through the "already on the private
                // disk" branch above.
                if (! Storage::disk(self::PUBLIC_DISK)->delete($relative)) {
                    $this->warn("  [partial] {$label} migrated, but the public copy could not be deleted and is STILL WEB-ACCESSIBLE: {$relative}");
                    $result['orphaned']++;

                    continue;
                }

                $this->line("  [ok]      {$label} migrated ({$sourceSize} bytes)");
            }

            if ($rowChanged && ! $dryRun) {
                $consultation->forceFill(['file_attachments' => array_values($rewritten)])->save();
            }
        }

        return $result;
    }

    /**
     * Stream one file from the public disk to the private one and report the
     * source size, so the caller can verify the copy before deleting anything.
     */
    private function copyToPrivateDisk(string $path): int
    {
        $sourceSize = Storage::disk(self::PUBLIC_DISK)->size($path);
        $stream = Storage::disk(self::PUBLIC_DISK)->readStream($path);

        if ($stream === false || $stream === null) {
            throw new \RuntimeException('Unable to open the source file for reading.');
        }

        Storage::disk(self::PRIVATE_DISK)->writeStream($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $sourceSize;
    }
}

