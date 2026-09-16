<?php

use App\Services\MedicalFileStorage;
use Cloudinary\Api\ApiResponse;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| MedicalFileStorage is the single place that decides where a medical file goes
| and how it comes back. Two properties are what make it worth having:
|
|  1. Nothing it stores is reachable without going through an authorizing
|     controller action — a Cloudinary asset is uploaded as delivery type
|     "authenticated" (its plain URL returns 401) and a local fallback lands on
|     a private disk the web server cannot serve.
|  2. What is persisted is a reference, not a URL, so a leaked database value is
|     not itself a way in.
|
| The Cloudinary shapes asserted here were confirmed against the live API with
| the installed SDK (cloudinary/cloudinary_php 3.1.3): an image returns a
| public_id with no extension plus a format, and a raw asset returns a public_id
| that already carries the extension and no format key at all.
*/

/**
 * A real Cloudinary\Api\ApiResponse, not a stand-in.
 *
 * The SDK declares UploadApi::upload(): ApiResponse, and Mockery enforces that
 * return type — a plain ArrayObject is rejected at the boundary, the service
 * catches the TypeError as an upload failure, and every assertion below then
 * passes or fails against the local fallback instead of the Cloudinary path it
 * meant to exercise.
 */
function fakeUploadResponse(array $payload): ApiResponse
{
    return new ApiResponse($payload, ['x-featureratelimit-limit' => [0], 'x-featureratelimit-reset' => [0], 'x-featureratelimit-remaining' => [0]]);
}

/*
|--------------------------------------------------------------------------
| Upload
|--------------------------------------------------------------------------
*/

it('uploads with authenticated delivery, never the default public type', function () {
    Storage::fake('message_attachments');

    Cloudinary::shouldReceive('uploadApi->upload')
        ->once()
        ->withArgs(function ($path, $options) {
            // The whole point: "upload" (the SDK default) would publish the
            // asset at a world-readable URL.
            expect($options['type'])->toBe('authenticated')
                ->and($options['folder'])->toBe('telemed_consultations')
                ->and($options['resource_type'])->toBe('auto');

            return true;
        })
        ->andReturn(fakeUploadResponse([
            'public_id' => 'telemed_consultations/ab12',
            'resource_type' => 'image',
            'format' => 'png',
        ]));

    $reference = app(MedicalFileStorage::class)->store(
        UploadedFile::fake()->create('scan.png', 10, 'image/png'),
        'telemed_consultations',
        'consultation-attachments/1'
    );

    expect($reference)->toBe('cloudinary:image:png:telemed_consultations/ab12');
});

it('stores a reference rather than any URL', function () {
    Storage::fake('message_attachments');

    Cloudinary::shouldReceive('uploadApi->upload')->once()->andReturn(fakeUploadResponse([
        'public_id' => 'message_attachments/cd34',
        'resource_type' => 'image',
        'format' => 'jpg',
        // A secure_url is present in every real response and must NOT be what
        // gets persisted — that is the habit this class exists to break.
        'secure_url' => 'https://res.cloudinary.com/demo/image/authenticated/s--x--/v1/message_attachments/cd34.jpg',
    ]));

    $reference = app(MedicalFileStorage::class)->store(
        UploadedFile::fake()->create('x.jpg', 10, 'image/jpeg'),
        'message_attachments',
        'message-attachments/1'
    );

    expect($reference)->not->toContain('http')
        ->and($reference)->not->toContain('res.cloudinary.com');
});

it('encodes a raw asset, whose public_id carries the extension and has no format', function () {
    Storage::fake('message_attachments');

    Cloudinary::shouldReceive('uploadApi->upload')->once()->andReturn(fakeUploadResponse([
        'public_id' => 'message_attachments/ef56.docx',
        'resource_type' => 'raw',
    ]));

    $reference = app(MedicalFileStorage::class)->store(
        UploadedFile::fake()->create('report.docx', 10),
        'message_attachments',
        'message-attachments/1'
    );

    expect($reference)->toBe('cloudinary:raw::message_attachments/ef56.docx');
});

it('falls back to the private disk when cloudinary throws, never the public one', function () {
    Storage::fake('public');
    Storage::fake('message_attachments');

    Cloudinary::shouldReceive('uploadApi->upload')
        ->once()
        ->andThrow(new Exception('Simulated Cloudinary outage'));

    $reference = app(MedicalFileStorage::class)->store(
        UploadedFile::fake()->create('scan.png', 10, 'image/png'),
        'telemed_consultations',
        'consultation-attachments/9'
    );

    Storage::disk('message_attachments')->assertExists($reference);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('treats a response with no public_id as a failed upload', function () {
    Storage::fake('message_attachments');

    // Nothing to sign later, so persisting it would create an unreadable row.
    Cloudinary::shouldReceive('uploadApi->upload')->once()->andReturn(fakeUploadResponse([
        'secure_url' => 'https://res.cloudinary.com/demo/image/authenticated/s--x--/v1/whatever.png',
    ]));

    $reference = app(MedicalFileStorage::class)->store(
        UploadedFile::fake()->create('scan.png', 10, 'image/png'),
        'telemed_consultations',
        'consultation-attachments/9'
    );

    expect($reference)->not->toStartWith('cloudinary:');
    Storage::disk('message_attachments')->assertExists($reference);
});

/*
|--------------------------------------------------------------------------
| Addressing
|--------------------------------------------------------------------------
*/

it('derives the same attachment key from every reference form', function (string $reference, string $expected) {
    expect(app(MedicalFileStorage::class)->attachmentKey($reference))->toBe($expected);
})->with([
    ['cloudinary:image:png:telemed_consultations/ab12', 'ab12'],
    ['cloudinary:raw::message_attachments/ef56.docx', 'ef56.docx'],
    ['consultation-attachments/5/gh78.jpg', 'gh78.jpg'],
    ['https://res.cloudinary.com/demo/image/upload/v1/telemed_consultations/ij90.png', 'ij90.png'],
]);

/*
|--------------------------------------------------------------------------
| Serving
|--------------------------------------------------------------------------
*/

it('answers a cloudinary reference with a signed, expiring url and never a bare delivery url', function () {
    Cloudinary::shouldReceive('uploadApi->privateDownloadUrl')
        ->once()
        ->withArgs(function ($publicId, $format, $options) {
            expect($publicId)->toBe('telemed_consultations/ab12')
                ->and($format)->toBe('png')
                ->and($options['type'])->toBe('authenticated')
                ->and($options['resource_type'])->toBe('image')
                // Time-limited: a delivery URL's own s--…-- signature never
                // expires, which is why it is not what gets handed out.
                ->and($options['expires_at'])->toBeGreaterThan(now()->getTimestamp());

            return true;
        })
        ->andReturn('https://api.cloudinary.com/v1_1/demo/image/download?signature=stub');

    $response = app(MedicalFileStorage::class)
        ->response('cloudinary:image:png:telemed_consultations/ab12', 'scan.png');

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toContain('signature=stub');
});

it('serves a private-disk reference as a stream, producing no url at all', function () {
    Storage::fake('message_attachments');
    Storage::disk('message_attachments')->put('consultation-attachments/3/scan.png', 'bytes');

    $response = app(MedicalFileStorage::class)
        ->response('consultation-attachments/3/scan.png', 'scan.png');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Location'))->toBeNull();
});

it('serves a legacy /storage path through the application instead of redirecting to it', function () {
    Storage::fake('public');
    Storage::disk('public')->put('consultation-attachments/old.jpg', 'legacy-bytes');

    // Rows written by the old public-disk fallback. Redirecting would hand the
    // caller the public URL this change exists to stop using, so the bytes are
    // streamed from behind the controller's authorization instead.
    foreach ([
        '/storage/consultation-attachments/old.jpg',
        'http://localhost/storage/consultation-attachments/old.jpg',
    ] as $legacy) {
        $response = app(MedicalFileStorage::class)->response($legacy, 'old.jpg');

        expect($response->getStatusCode())->toBe(200)
            ->and($response->headers->get('Location'))->toBeNull();
    }
});

it('still redirects a legacy public cloudinary url, which is already exposed', function () {
    Storage::fake('public');

    $legacy = 'https://res.cloudinary.com/demo/image/upload/v1/message_attachments/legacy.png';
    $response = app(MedicalFileStorage::class)->response($legacy, 'legacy.png', false);

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe($legacy);
});

/*
|--------------------------------------------------------------------------
| Deletion
|--------------------------------------------------------------------------
*/

it('deletes a private-disk file but leaves remote references alone', function () {
    Storage::fake('message_attachments');
    Storage::disk('message_attachments')->put('consultation-prescriptions/1/rx.pdf', 'bytes');

    $storage = app(MedicalFileStorage::class);

    $storage->delete('consultation-prescriptions/1/rx.pdf');
    Storage::disk('message_attachments')->assertMissing('consultation-prescriptions/1/rx.pdf');

    // Neither of these should reach the disk, or throw.
    $storage->delete('cloudinary:image:png:consultation_prescriptions/ab12');
    $storage->delete('https://res.cloudinary.com/demo/image/upload/v1/x.png');
    $storage->delete(null);
});
