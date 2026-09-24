<?php

declare(strict_types=1);

use App\Models\Image;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use League\Flysystem\UnableToRetrieveMetadata;

// Downloading the original via GET /api/v1/images/{id}/download.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    fake_image_storage();
    Queue::fake();
});

it('streams the original bytes unchanged', function (): void {
    $image = stored_image('exif-iptc.jpg');

    $response = $this->get("/api/v1/images/{$image->id}/download")->assertOk();

    // Byte-identical: the original, EXIF/IPTC included, never a re-encoded copy.
    expect($response->streamedContent())->toBe(fixture_contents('exif-iptc.jpg'))
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen(fixture_contents('exif-iptc.jpg')));
});

it('sends the stored content type of every allowed format', function (string $fixture, string $mimeType, string $extension): void {
    $image = stored_image($fixture, ['mime_type' => $mimeType, 'extension' => $extension, 'original_name' => "photo.{$extension}"]);

    $this->get("/api/v1/images/{$image->id}/download")
        ->assertOk()
        ->assertHeader('Content-Type', $mimeType)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
})->with([
    'JPEG' => ['valid.jpg', 'image/jpeg', 'jpg'],
    'PNG' => ['valid.png', 'image/png', 'png'],
    'WebP' => ['valid.webp', 'image/webp', 'webp'],
    'TIFF' => ['valid.tiff', 'image/tiff', 'tif'],
    'BMP' => ['valid.bmp', 'image/bmp', 'bmp'],
]);

it('offers the original file name as an attachment, with an ASCII fallback', function (): void {
    $image = stored_image('valid.tiff', ['original_name' => 'Zażółć gęślą jaźń.tif', 'extension' => 'tif', 'mime_type' => 'image/tiff']);

    $this->get("/api/v1/images/{$image->id}/download")
        ->assertOk()
        ->assertHeader(
            'Content-Disposition',
            "attachment; filename=\"Zazolc gesla jazn.tif\"; filename*=utf-8''Za%C5%BC%C3%B3%C5%82%C4%87%20g%C4%99%C5%9Bl%C4%85%20ja%C5%BA%C5%84.tif",
        );
});

it('sends the stored content type rather than sniffing the file', function (): void {
    // Deliberately inconsistent: only the stored value can produce this header.
    $image = stored_image('valid.jpg', ['mime_type' => 'image/png', 'extension' => 'png', 'original_name' => 'photo.png']);

    $this->get("/api/v1/images/{$image->id}/download")->assertOk()->assertHeader('Content-Type', 'image/png');
});

it('names the download by the original name, not the stored ULID name', function (): void {
    $image = stored_image('valid.png', ['original_name' => 'Holiday.png', 'extension' => 'png', 'mime_type' => 'image/png']);

    $this->get("/api/v1/images/{$image->id}/download")->assertHeader('Content-Disposition', 'attachment; filename=Holiday.png');
});

it('appends the detected extension when the uploaded name belongs to another type', function (): void {
    $url = post_image(['file' => uploaded_fixture('valid.webp', 'photo.jpg')])->assertCreated()->json('data.download_url');

    $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('Content-Disposition', 'attachment; filename=photo.jpg.webp');
});

it('sends a single, percent-encoded disposition for a name with line breaks', function (): void {
    $image = stored_image('valid.jpg', ['original_name' => "a\r\nX-Evil: 1.jpg", 'extension' => 'jpg', 'mime_type' => 'image/jpeg']);

    $response = $this->get("/api/v1/images/{$image->id}/download")->assertOk();

    expect($response->headers->all('content-disposition'))->toBe(["attachment; filename=\"a X-Evil: 1.jpg\"; filename*=utf-8''a%0D%0AX-Evil%3A%201.jpg"])
        ->and($response->headers->has('X-Evil'))->toBeFalse();
});

it('answers HEAD with the download headers', function (): void {
    $image = stored_image('valid.png', ['original_name' => 'Holiday.png', 'extension' => 'png', 'mime_type' => 'image/png']);

    $this->call('HEAD', "/api/v1/images/{$image->id}/download")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Length', (string) strlen(fixture_contents('valid.png')))
        ->assertHeader('Content-Disposition', 'attachment; filename=Holiday.png');
});

it('downloads an upload via the download_url of the upload response', function (): void {
    $url = post_image(['file' => uploaded_fixture('exif-iptc.jpg', 'Kraków.jpg')])->assertCreated()->json('data.download_url');

    $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    expect($response->streamedContent())->toBe(fixture_contents('exif-iptc.jpg'))
        ->and($response->headers->get('Content-Disposition'))->toBe("attachment; filename=Krakow.jpg; filename*=utf-8''Krak%C3%B3w.jpg");
});

it('responds with a JSON 404 for an unknown or malformed id', function (string $id): void {
    $this->get("/api/v1/images/{$id}/download")
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json');
})->with([
    'unknown ULID' => fn (): string => Str::lower((string) Str::ulid()),
    'not a ULID' => 'not-an-id',
]);

it('reports a record whose original is missing as a server error', function (): void {
    // A 404 would hide a storage inconsistency from monitoring and contradict the listing.
    Exceptions::fake();
    $image = Image::factory()->create();

    $this->get("/api/v1/images/{$image->id}/download")
        ->assertInternalServerError()
        ->assertHeader('Content-Type', 'application/json');

    Exceptions::assertReported(UnableToRetrieveMetadata::class);
});
