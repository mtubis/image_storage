<?php

declare(strict_types=1);

use App\Models\Image;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use League\Flysystem\UnableToRetrieveMetadata;

// Every API error is JSON, even for a plain request without `Accept: application/json`
// (a browser following a download link), and never discloses internals in production.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    fake_image_storage();
    config(['app.debug' => false]);
});

it('answers every 404 with the same JSON body', function (string $method, string $uri): void {
    // Laravel's default names the model class and echoes the id; the client needs neither.
    $this->call($method, $uri)
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['message' => 'Not found.']);
})->with([
    'unknown image download' => fn (): array => ['GET', '/api/v1/images/'.Str::lower((string) Str::ulid()).'/download'],
    'unknown image delete' => fn (): array => ['DELETE', '/api/v1/images/'.Str::lower((string) Str::ulid())],
    'malformed id' => ['DELETE', '/api/v1/images/not-an-id'],
    'unknown route' => ['GET', '/api/v1/unknown'],
    'unknown version' => ['GET', '/api/v2/images'],
    'API root' => ['GET', '/api'],
]);

it('answers a 404 the same way in debug mode', function (): void {
    config(['app.debug' => true]);

    $this->delete('/api/v1/images/not-an-id')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not found.']);
});

it('answers an unsupported method with a JSON 405 and the allowed methods', function (): void {
    $this->put('/api/v1/images')
        ->assertMethodNotAllowed()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('Allow', 'GET, HEAD, POST')
        ->assertJsonStructure(['message']);
});

it('answers a server error with a generic JSON 500', function (): void {
    Exceptions::fake();
    $image = Image::factory()->create();

    // A missing original: the exception message contains the storage path.
    $response = $this->get("/api/v1/images/{$image->id}/download")
        ->assertInternalServerError()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['message' => 'Server Error']);

    expect($response->getContent())->not->toContain($image->original_path);
    Exceptions::assertReported(UnableToRetrieveMetadata::class);
});

it('answers a body over post_max_size with a JSON 413', function (): void {
    // Normally stopped by nginx first (its own JSON 413, docker/nginx); Laravel's ValidatePostSize is
    // the backstop. Assumes php.ini's post_max_size (10M in docker/php/php.ini) is below 11 MB.
    $this->call('POST', '/api/v1/images', server: ['CONTENT_LENGTH' => (string) (11 * 1024 * 1024)])
        ->assertStatus(413)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message']);
});
