<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

// Validation of POST /api/v1/images. Files are real uploads of the committed fixtures (or
// real images built at runtime), because the type rule sniffs file content, not the name.

// Accepted requests store files, a row and a job; isolate them.
uses(RefreshDatabase::class);

beforeEach(function (): void {
    fake_image_storage();
    Queue::fake();
});

it('accepts every allowed format', function (string $fixture): void {
    post_image(['file' => uploaded_fixture($fixture)])->assertPassedValidation();
})->with([
    'JPEG' => 'valid.jpg',
    'PNG' => 'valid.png',
    'WebP' => 'valid.webp',
    'TIFF' => 'valid.tiff',
    'BMP' => 'valid.bmp',
    'JPEG with EXIF and IPTC' => 'exif-iptc.jpg',
    'TIFF with EXIF and IPTC' => 'exif-iptc.tiff',
]);

it('accepts allowed file extensions in any case', function (string $clientName): void {
    post_image(['file' => uploaded_fixture('valid.jpg', $clientName)])->assertPassedValidation();
})->with(['PHOTO.JPG', 'photo.jpeg', 'photo.Jpeg', 'scan.TIF']);

it('trusts file content over a mismatched but allowed extension', function (): void {
    // WebP saved as ".jpg" is common in the wild; the stored type is the detected one.
    post_image(['file' => uploaded_fixture('valid.webp', 'photo.jpg')])->assertPassedValidation();
});

it('rejects a file of a disallowed type', function (UploadedFile $file): void {
    post_image(['file' => $file])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'must be a file of type: jpg, jpeg, png, webp, tif, tiff, bmp'])
        // "bail": no confusing dimension error on top of the type error.
        ->assertJsonCount(1, 'errors.file');
})->with([
    'GIF' => fn (): UploadedFile => uploaded_fixture('not-allowed.gif'),
    'PDF' => fn (): UploadedFile => uploaded_fixture('not-an-image.pdf'),
    'PDF renamed to .jpg' => fn (): UploadedFile => uploaded_fixture('not-an-image.pdf', 'photo.jpg'),
    'GIF renamed to .png' => fn (): UploadedFile => uploaded_fixture('not-allowed.gif', 'photo.png'),
    'plain text renamed to .tiff' => fn (): UploadedFile => uploaded_file('not an image', 'photo.tiff'),
    'empty file' => fn (): UploadedFile => uploaded_file('', 'photo.jpg'),
]);

it('rejects a PHP file even when its content is a valid image', function (): void {
    post_image(['file' => uploaded_fixture('valid.jpg', 'shell.php')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'must be a file of type'])
        ->assertJsonCount(1, 'errors.file');
});

it('rejects a missing or non-file value', function (mixed $file): void {
    post_image(['file' => $file])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
})->with([
    'missing' => null,
    'string' => 'valid.jpg',
    'array' => [['valid.jpg']],
]);

it('rejects an upload that failed on the PHP side', function (): void {
    $file = uploaded_file('', 'photo.jpg', UPLOAD_ERR_INI_SIZE);

    post_image(['file' => $file])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'failed to upload']);
});

it('accepts a file of exactly the maximum size', function (): void {
    post_image(['file' => jpeg_of_size(5120 * 1024)])->assertPassedValidation();
});

it('rejects a file larger than the maximum size', function (): void {
    post_image(['file' => jpeg_of_size(5120 * 1024 + 1)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'must not be greater than 5120 kilobytes'])
        ->assertJsonCount(1, 'errors.file');
});

it('rejects a truncated JPEG', function (): void {
    // Its header is intact, so type, size and dimensions pass; libjpeg would still render it.
    $jpeg = fixture_contents('valid.jpg');

    post_image(['file' => uploaded_file(substr($jpeg, 0, intdiv(strlen($jpeg), 2)), 'photo.jpg')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'The file is incomplete or damaged.'])
        ->assertJsonCount(1, 'errors.file');
});

it('rejects an image below the minimum resolution', function (string $fixture): void {
    post_image(['file' => uploaded_fixture($fixture)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'must be between 500×500 and 10000×10000 pixels']);
})->with([
    '499×500' => 'too-narrow.jpg',
    '500×499' => 'too-short.jpg',
]);

// One edge at a time: a 10000×10000 test image would need ~800 MB in Imagick (Q16).
it('accepts an image at the maximum resolution', function (int $width, int $height): void {
    post_image(['file' => png_of_dimensions($width, $height)])->assertPassedValidation();
})->with([
    '10000×500' => [10000, 500],
    '500×10000' => [500, 10000],
]);

it('rejects an image above the maximum resolution', function (int $width, int $height): void {
    post_image(['file' => png_of_dimensions($width, $height)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'must be between 500×500 and 10000×10000 pixels']);
})->with([
    '10001×500' => [10001, 500],
    '500×10001' => [500, 10001],
]);

it('accepts a valid uploader name', function (string $name): void {
    post_image(['uploader_name' => $name])->assertPassedValidation();
})->with([
    'with diacritics' => 'Zażółć Gęślą-Jaźń',
    'with apostrophe and non-Latin script' => "O'Brien Ελένη 李",
    // The limit counts characters, not bytes.
    '100 multibyte characters' => str_repeat('Ł', 100),
]);

it('rejects an invalid uploader name', function (mixed $name, string $message): void {
    post_image(['uploader_name' => $name])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['uploader_name' => $message]);
})->with([
    'missing' => [null, 'The name field is required.'],
    'empty' => ['', 'The name field is required.'],
    // TrimStrings + ConvertEmptyStringsToNull turn it into null.
    'whitespace only' => ['   ', 'The name field is required.'],
    'array' => [['Jan'], 'The name field must be a string.'],
    '101 characters' => [str_repeat('a', 101), 'The name field must not be greater than 100 characters.'],
    // Would otherwise fail later as a 500 (MariaDB "Incorrect string value", json_encode).
    'invalid UTF-8' => ["Jan Kowalsk\xC3\x28", 'The name field format is invalid.'],
    'NUL byte' => ["Jan\x00Kowalski", 'The name field format is invalid.'],
    'line break' => ["Jan\nKowalski", 'The name field format is invalid.'],
]);

it('accepts a valid uploader e-mail', function (string $email): void {
    post_image(['uploader_email' => $email])->assertPassedValidation();
})->with([
    'plain' => 'jan@example.com',
    'with plus tag' => 'jan+images@example.com',
    'exactly 255 characters' => str_repeat('a', 64).'@'.str_repeat('b', 62).'.'.str_repeat('c', 62).'.'.str_repeat('d', 61).'.pl',
]);

it('rejects an invalid uploader e-mail', function (mixed $email, string $message): void {
    post_image(['uploader_email' => $email])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['uploader_email' => $message]);
})->with([
    'missing' => [null, 'The e-mail field is required.'],
    'empty' => ['', 'The e-mail field is required.'],
    'without @' => ['jan.example.com', 'The e-mail field must be a valid email address.'],
    'without local part' => ['@example.com', 'The e-mail field must be a valid email address.'],
    'array' => [['jan@example.com'], 'The e-mail field must be a string.'],
    'invalid UTF-8' => ["jan\xC3\x28@example.com", 'The e-mail field must be a valid email address.'],
    '256 characters' => [
        str_repeat('a', 64).'@'.str_repeat('b', 62).'.'.str_repeat('c', 62).'.'.str_repeat('d', 62).'.pl',
        'The e-mail field must not be greater than 255 characters.',
    ],
]);

it('reports every invalid field at once as JSON', function (): void {
    test()->post('/api/v1/images')
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonValidationErrors(['file', 'uploader_name', 'uploader_email']);
});

// A regression guard: Laravel's default messages don't use :input, a custom one could.
it('does not echo submitted values back in the error response', function (): void {
    $response = post_image([
        'uploader_name' => 'secret-name'.str_repeat('a', 101),
        'uploader_email' => 'secret-but-invalid',
    ])->assertUnprocessable();

    expect($response->getContent())->not->toContain('secret-');
});
