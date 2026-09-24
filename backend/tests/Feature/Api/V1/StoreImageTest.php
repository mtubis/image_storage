<?php

declare(strict_types=1);

use App\Jobs\FetchImageTemperature;
use App\Models\Image;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;

// Storing an upload via POST /api/v1/images; validation itself is StoreImageValidationTest.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    fake_image_storage();
    Queue::fake();
    $this->travelTo(new DateTimeImmutable('2026-09-25T08:30:00Z'));
});

it('responds with the created image', function (): void {
    $response = post_image(['file' => uploaded_fixture('valid.jpg', 'Holiday photo.jpg')])->assertCreated();
    $image = Image::query()->sole();

    $response->assertExactJson([
        'data' => [
            'id' => $image->id,
            'original_name' => 'Holiday photo.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen(fixture_contents('valid.jpg')),
            'width' => 500,
            'height' => 500,
            'thumbnail_url' => config('app.url')."/storage/thumbnails/{$image->id}.webp",
            'uploader_name' => 'Jan Kowalski',
            'temperature_c' => null,
            'created_at' => '2026-09-25T08:30:00Z',
        ],
    ]);
});

it('never exposes the e-mail, storage paths or metadata', function (): void {
    $response = post_image(['file' => uploaded_fixture('exif-iptc.jpg')])->assertCreated();

    $response->assertJson(fn (AssertableJson $json): AssertableJson => $json->has('data', fn (AssertableJson $data): AssertableJson => $data
        ->missing('uploader_email')
        ->missing('original_path')
        ->missing('thumbnail_path')
        ->missing('metadata')
        ->etc()));
    expect($response->getContent())->not->toContain('jan.kowalski@example.com');
});

it('stores every allowed format under its detected type', function (string $fixture, string $mimeType, string $extension): void {
    post_image(['file' => uploaded_fixture($fixture)])->assertCreated();
    $image = Image::query()->sole();

    expect($image->only(['mime_type', 'extension', 'width', 'height', 'size_bytes', 'uploader_email']))->toBe([
        'mime_type' => $mimeType,
        'extension' => $extension,
        'width' => 500,
        'height' => 500,
        'size_bytes' => strlen(fixture_contents($fixture)),
        'uploader_email' => 'jan.kowalski@example.com',
    ])
        ->and(Storage::disk('originals')->get($image->original_path))->toBe(fixture_contents($fixture))
        ->and(Storage::disk('originals')->path($image->original_path))->toEndWith(".{$extension}")
        ->and(new finfo(FILEINFO_MIME_TYPE)->buffer((string) Storage::disk('thumbnails')->get($image->thumbnail_path)))->toBe('image/webp');
})->with([
    'JPEG' => ['valid.jpg', 'image/jpeg', 'jpg'],
    'PNG' => ['valid.png', 'image/png', 'png'],
    'WebP' => ['valid.webp', 'image/webp', 'webp'],
    'TIFF' => ['valid.tiff', 'image/tiff', 'tif'],
    'BMP' => ['valid.bmp', 'image/bmp', 'bmp'],
]);

it('stores the detected type, not the one the file name claims', function (): void {
    post_image(['file' => uploaded_fixture('valid.webp', 'photo.jpg')])->assertCreated()
        ->assertJsonPath('data.original_name', 'photo.jpg')
        ->assertJsonPath('data.extension', 'webp')
        ->assertJsonPath('data.mime_type', 'image/webp');

    expect(Image::query()->sole()->original_path)->toEndWith('.webp');
});

it('reports the dimensions as displayed', function (): void {
    // Stored 640×500 with EXIF Orientation 6.
    post_image(['file' => uploaded_fixture('exif-iptc.jpg')])->assertCreated()
        ->assertJsonPath('data.width', 500)
        ->assertJsonPath('data.height', 640);
});

it('stores the EXIF and IPTC metadata', function (): void {
    post_image(['file' => uploaded_fixture('exif-iptc.jpg')])->assertCreated();

    expect(Image::query()->sole()->metadata)->toHaveKeys(['exif.IFD0.Make', 'exif.EXIF', 'iptc']);
});

it('serves the thumbnail at the returned URL path', function (): void {
    $url = post_image()->assertCreated()->json('data.thumbnail_url');
    $path = str($url)->after('/storage/thumbnails/')->toString();

    Storage::disk('thumbnails')->assertExists($path);
});

it('queues fetching the temperature', function (): void {
    post_image()->assertCreated();

    Queue::assertPushed(FetchImageTemperature::class, fn (FetchImageTemperature $job): bool => $job->image->is(Image::query()->sole()));
});

it('keeps the client file name only for display, without any path', function (string $clientName, string $stored): void {
    post_image(['file' => uploaded_fixture('valid.jpg', $clientName)])->assertCreated()
        ->assertJsonPath('data.original_name', $stored);

    expect(Image::query()->sole()->original_path)->not->toContain('photo');
})->with([
    'Unix path' => ['../../etc/photo.jpg', 'photo.jpg'],
    'Windows path' => ['C:\\Users\\jan\\photo.jpg', 'photo.jpg'],
    'invalid UTF-8' => ["ph\xC3\x28oto.jpg", 'ph?(oto.jpg'],
    'control characters' => ["pho\x00to\n.jpg", 'photo.jpg'],
    // U+202E would display the name as "invoicegpj.jpg" reversed, e.g. to fake an extension.
    'bidi override' => ["invoice\u{202E}gpj.jpg", 'invoicegpj.jpg'],
    'too long' => [str_repeat('ł', 300).'.jpg', str_repeat('ł', 251).'.jpg'],
    'too long without extension' => [str_repeat('photo', 60), str_repeat('photo', 51)],
]);

// PNG, WebP, TIFF and BMP decoders fail on a damaged body behind a valid header; only
// decoding notices, after validation passed. (A cut TIFF usually loses its IFD, which sits at
// the end, and is already refused by validation.)
it('rejects an image that cannot be decoded and stores nothing', function (string $fixture): void {
    Log::spy();
    $contents = fixture_contents($fixture);

    post_image(['file' => uploaded_file(substr($contents, 0, intdiv(strlen($contents), 2)), $fixture)])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'The file is incomplete or damaged.',
            'errors' => ['file' => ['The file is incomplete or damaged.']],
        ]);

    expect(Image::query()->count())->toBe(0)
        ->and(Storage::disk('originals')->allFiles())->toBe([])
        ->and(Storage::disk('thumbnails')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
    // ImageMagick's own text goes to the log for diagnosis, not to the client.
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'An uploaded image could not be decoded.'
        && str_contains((string) $context['reason'], 'Cannot generate a thumbnail'));
})->with(['valid.png', 'valid.webp', 'valid.bmp']);

it('answers with a JSON 500 and stores nothing when persisting fails', function (): void {
    Image::creating(fn (): never => throw new RuntimeException('Database is gone.'));

    post_image()->assertServerError()->assertHeader('Content-Type', 'application/json');

    expect(Storage::disk('originals')->allFiles())->toBe([])
        ->and(Storage::disk('thumbnails')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
});
