<?php

declare(strict_types=1);

use App\Models\Image;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

// assertValid() alone would also pass on a 404, so pin the status of a stored upload.
TestResponse::macro('assertPassedValidation', function (): TestResponse {
    /** @var TestResponse<Response> $this */
    return $this->assertValid()->assertCreated();
});

/**
 * Replace both image disks with temporary ones, so no test writes to the real storage.
 */
function fake_image_storage(): void
{
    Storage::fake('originals');
    // A fake disk drops the configured URL; keep it, the API returns thumbnail URLs.
    Storage::fake('thumbnails', ['url' => config('filesystems.disks.thumbnails.url')]);
}

/**
 * An image row whose original on the fake disk holds the given fixture's bytes.
 *
 * @param  array<string, mixed>  $attributes
 */
function stored_image(string $fixture, array $attributes = []): Image
{
    $image = Image::factory()->create($attributes);
    Storage::disk('originals')->put($image->original_path, fixture_contents($fixture));

    return $image;
}

/**
 * Absolute path of a committed fixture from tests/Fixtures (see its README.md).
 */
function fixture_path(string $name): string
{
    $path = __DIR__.'/Fixtures/'.$name;

    // Fail loudly: a typo would otherwise surface as a confusing validation or decode error.
    if (! is_file($path)) {
        throw new RuntimeException("Unknown test fixture [{$name}].");
    }

    return $path;
}

/**
 * Bytes of a committed fixture from tests/Fixtures.
 */
function fixture_contents(string $name): string
{
    return (string) file_get_contents(fixture_path($name));
}

/**
 * A real (not faked) upload of the given bytes, backed by a temporary file.
 *
 * UploadedFile::fake() reports a MIME type derived from the file *name*, which would make
 * content-sniffing rules like "mimes" pass for any bytes; a real UploadedFile sniffs content.
 */
function uploaded_file(string $contents, string $clientName, int $error = UPLOAD_ERR_OK): UploadedFile
{
    // tmpfile() is deleted when its handle is closed; keeping the handles alive until the
    // process ends lets tests use the path without their own cleanup.
    static $handles = [];

    $handle = tmpfile();

    if ($handle === false || fwrite($handle, $contents) !== strlen($contents)) {
        throw new RuntimeException('Cannot create a temporary upload file.');
    }

    $handles[] = $handle;

    return new UploadedFile(stream_get_meta_data($handle)['uri'], $clientName, null, $error, test: true);
}

/**
 * A real upload of a copy of a committed fixture, so code under test that moves the
 * upload can never touch tests/Fixtures.
 */
function uploaded_fixture(string $name, ?string $clientName = null): UploadedFile
{
    return uploaded_file((string) file_get_contents(fixture_path($name)), $clientName ?? $name);
}

/**
 * Upload a valid image with valid uploader data, overriding the given fields.
 *
 * A plain multipart POST without "Accept: application/json", like a browser form: the API
 * must still answer with JSON, never with a redirect.
 *
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<Response>
 */
function post_image(array $overrides = []): TestResponse
{
    return test()->post('/api/v1/images', array_merge([
        'file' => uploaded_fixture('valid.jpg'),
        'uploader_name' => 'Jan Kowalski',
        'uploader_email' => 'jan.kowalski@example.com',
    ], $overrides));
}

/**
 * valid.jpg padded with trailing bytes to an exact size. Decoders and content sniffing
 * ignore data after the JPEG EOI marker, so only the size rule can tell the difference.
 */
function jpeg_of_size(int $bytes): UploadedFile
{
    $jpeg = (string) file_get_contents(fixture_path('valid.jpg'));

    return uploaded_file(str_pad($jpeg, $bytes, "\0"), 'large.jpg');
}

/**
 * A flat PNG of the given dimensions, built at runtime rather than committed: only the
 * header dimensions matter to the tests that use it.
 *
 * The app caps ImageMagick's width/height at the validation maximum (AppServiceProvider), so
 * building an image beyond it lifts those process-wide limits for the duration of the call.
 */
function png_of_dimensions(int $width, int $height): UploadedFile
{
    $limits = [
        Imagick::RESOURCETYPE_WIDTH => Imagick::getResourceLimit(Imagick::RESOURCETYPE_WIDTH),
        Imagick::RESOURCETYPE_HEIGHT => Imagick::getResourceLimit(Imagick::RESOURCETYPE_HEIGHT),
    ];

    try {
        foreach (array_keys($limits) as $type) {
            Imagick::setResourceLimit($type, max($width, $height));
        }

        $image = new Imagick;
        $image->newImage($width, $height, 'white', 'png');

        return uploaded_file($image->getImageBlob(), "{$width}x{$height}.png");
    } finally {
        foreach ($limits as $type => $limit) {
            Imagick::setResourceLimit($type, (int) $limit);
        }
    }
}
