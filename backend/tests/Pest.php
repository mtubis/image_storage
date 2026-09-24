<?php

declare(strict_types=1);

use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

// assertValid() alone would also pass on a 404, so pin the status an upload reaches after
// validation: 501 until step 1.8 implements storing, then 201.
TestResponse::macro('assertPassedValidation', function (): TestResponse {
    /** @var TestResponse<Response> $this */
    return $this->assertValid()->assertStatus(501);
});

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
 */
function png_of_dimensions(int $width, int $height): UploadedFile
{
    $image = new Imagick;
    $image->newImage($width, $height, 'white', 'png');

    return uploaded_file($image->getImageBlob(), "{$width}x{$height}.png");
}
