<?php

declare(strict_types=1);

use App\Models\Image;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Deleting an image via DELETE /api/v1/images/{id}.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    fake_image_storage();
    Queue::fake();
});

it('deletes the image with its files and responds with an empty 204', function (): void {
    $image = stored_image('valid.jpg');

    $this->deleteJson("/api/v1/images/{$image->id}")->assertNoContent();

    expect(Image::query()->count())->toBe(0)
        ->and(Storage::disk('originals')->allFiles())->toBe([])
        ->and(Storage::disk('thumbnails')->allFiles())->toBe([]);
});

it('removes an uploaded image, its files, listing entry and download', function (): void {
    $upload = post_image()->assertCreated();

    $this->delete("/api/v1/images/{$upload->json('data.id')}")->assertNoContent();

    expect(Storage::disk('originals')->allFiles())->toBe([])
        ->and(Storage::disk('thumbnails')->allFiles())->toBe([]);
    $this->getJson('/api/v1/images')->assertOk()->assertJsonCount(0, 'data');
    $this->get($upload->json('data.download_url'))->assertNotFound();
});

it('responds with a JSON 404 for an unknown or malformed id', function (string $id): void {
    $image = stored_image('valid.jpg');

    // A plain request, like a browser's: the API still answers with JSON.
    $this->delete("/api/v1/images/{$id}")
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json');

    expect(Image::query()->sole()->is($image))->toBeTrue();
})->with([
    'unknown ULID' => fn (): string => Str::lower((string) Str::ulid()),
    'not a ULID' => 'not-an-id',
]);

// See DownloadImageTest: MariaDB would otherwise match the upper-case spelling.
it('responds with a JSON 404 for an existing id in upper case', function (): void {
    $image = stored_image('valid.jpg');

    $this->delete('/api/v1/images/'.Str::upper($image->id))->assertNotFound();

    expect(Image::query()->sole()->is($image))->toBeTrue();
});

it('responds with 404 to a repeated delete', function (): void {
    $image = stored_image('valid.jpg');

    $this->deleteJson("/api/v1/images/{$image->id}")->assertNoContent();
    $this->deleteJson("/api/v1/images/{$image->id}")->assertNotFound();
});
