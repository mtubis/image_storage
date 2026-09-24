<?php

declare(strict_types=1);

use App\Actions\Images\StoreImage;
use App\Data\StoreImageData;
use App\Exceptions\ThumbnailGenerationFailed;
use App\Jobs\FetchImageTemperature;
use App\Models\Image;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToWriteFile;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    fake_image_storage();
    Queue::fake();
});

function store_image_data(string $fixture = 'valid.jpg', string $mimeType = 'image/jpeg', string $extension = 'jpg'): StoreImageData
{
    return new StoreImageData(
        contents: fixture_contents($fixture),
        originalName: 'Holiday photo.JPG',
        mimeType: $mimeType,
        extension: $extension,
        uploaderName: 'Jan Kowalski',
        uploaderEmail: 'jan.kowalski@example.com',
    );
}

it('persists the image with its uploader and file details', function (): void {
    $image = resolve(StoreImage::class)->handle(store_image_data());

    expect(Image::query()->sole()->is($image))->toBeTrue()
        ->and($image->only(['original_name', 'extension', 'mime_type', 'size_bytes', 'width', 'height', 'uploader_name', 'uploader_email']))
        ->toBe([
            'original_name' => 'Holiday photo.JPG',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen(fixture_contents('valid.jpg')),
            'width' => 500,
            'height' => 500,
            'uploader_name' => 'Jan Kowalski',
            'uploader_email' => 'jan.kowalski@example.com',
        ])
        ->and($image->temperature_c)->toBeNull();
});

it('stores the original unchanged under a ULID-based name on the private disk', function (): void {
    $image = resolve(StoreImage::class)->handle(store_image_data());

    expect($image->original_path)->toBe("{$image->id}.jpg")
        ->and(Storage::disk('originals')->get($image->original_path))->toBe(fixture_contents('valid.jpg'));
});

it('stores a WebP thumbnail under a ULID-based name on the public disk', function (): void {
    $image = resolve(StoreImage::class)->handle(store_image_data());
    $thumbnail = (string) Storage::disk('thumbnails')->get($image->thumbnail_path);

    expect($image->thumbnail_path)->toBe("{$image->id}.webp")
        ->and(new finfo(FILEINFO_MIME_TYPE)->buffer($thumbnail))->toBe('image/webp');
});

it('stores the displayed dimensions', function (): void {
    $image = resolve(StoreImage::class)->handle(store_image_data('exif-iptc.jpg'));

    // Stored 640×500 with EXIF Orientation 6.
    expect([$image->width, $image->height])->toBe([500, 640]);
});

it('stores the extracted metadata', function (): void {
    $image = resolve(StoreImage::class)->handle(store_image_data('exif-iptc.jpg'));

    expect($image->refresh()->metadata)->toHaveKeys(['exif.IFD0.Model', 'iptc']);
});

it('stores no metadata when the image has none', function (): void {
    $image = resolve(StoreImage::class)->handle(store_image_data('valid.png', 'image/png', 'png'));

    expect($image->refresh()->metadata)->toBeNull();
});

it('queues fetching the temperature for the stored image', function (): void {
    $image = resolve(StoreImage::class)->handle(store_image_data());

    Queue::assertPushed(FetchImageTemperature::class, fn (FetchImageTemperature $job): bool => $job->image->is($image));
});

it('stores nothing when the image cannot be decoded', function (): void {
    $data = new StoreImageData(
        contents: substr(fixture_contents('valid.png'), 0, 1000),
        originalName: 'broken.png',
        mimeType: 'image/png',
        extension: 'png',
        uploaderName: 'Jan Kowalski',
        uploaderEmail: 'jan.kowalski@example.com',
    );

    expect(fn (): Image => resolve(StoreImage::class)->handle($data))->toThrow(ThumbnailGenerationFailed::class)
        ->and(Storage::disk('originals')->allFiles())->toBe([])
        ->and(Storage::disk('thumbnails')->allFiles())->toBe([])
        ->and(Image::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('removes both stored files when persisting fails', function (): void {
    Image::creating(fn (): never => throw new RuntimeException('Database is gone.'));

    expect(fn (): Image => resolve(StoreImage::class)->handle(store_image_data()))->toThrow(RuntimeException::class, 'Database is gone.')
        ->and(Storage::disk('originals')->allFiles())->toBe([])
        ->and(Storage::disk('thumbnails')->allFiles())->toBe([])
        ->and(Image::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

/**
 * A disk whose writes fail, as a full or unreachable storage would; deletes succeed.
 */
function failing_disk(): Filesystem
{
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->andThrow(UnableToWriteFile::atLocation('any', 'Disk is full.'));
    $disk->shouldReceive('delete')->andReturnTrue();

    return $disk;
}

it('stores nothing when storing the original fails', function (): void {
    Storage::set('originals', failing_disk());

    expect(fn (): Image => resolve(StoreImage::class)->handle(store_image_data()))->toThrow(UnableToWriteFile::class)
        ->and(Storage::disk('thumbnails')->allFiles())->toBe([])
        ->and(Image::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('removes the stored original when storing the thumbnail fails', function (): void {
    Storage::set('thumbnails', failing_disk());

    expect(fn (): Image => resolve(StoreImage::class)->handle(store_image_data()))->toThrow(UnableToWriteFile::class)
        ->and(Storage::disk('originals')->allFiles())->toBe([])
        ->and(Image::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('reports the persisting failure, not a failed cleanup', function (): void {
    Exceptions::fake();
    $originals = Mockery::mock(Filesystem::class);
    $originals->shouldReceive('put')->andReturnTrue();
    $originals->shouldReceive('delete')->andThrow(UnableToDeleteFile::atLocation('any', 'Disk is gone.'));
    Storage::set('originals', $originals);
    Image::creating(fn (): never => throw new RuntimeException('Database is gone.'));

    expect(fn (): Image => resolve(StoreImage::class)->handle(store_image_data()))->toThrow(RuntimeException::class, 'Database is gone.')
        // The second disk is still cleaned up after the first one failed.
        ->and(Storage::disk('thumbnails')->allFiles())->toBe([]);

    Exceptions::assertReported(UnableToDeleteFile::class);
});

// The job is pushed after the commit, so a queue failure can no longer undo the row; the
// temperature is optional, so the upload stands and the failure is only reported.
it('keeps the stored image when queueing the temperature job fails', function (): void {
    Exceptions::fake();
    $this->mock(Dispatcher::class)->shouldReceive('dispatch')->andThrow(new RuntimeException('Queue is down.'));

    $image = resolve(StoreImage::class)->handle(store_image_data());

    expect(Image::query()->sole()->is($image))->toBeTrue()
        ->and(Storage::disk('originals')->exists($image->original_path))->toBeTrue()
        ->and(Storage::disk('thumbnails')->exists($image->thumbnail_path))->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Queue is down.');
});
