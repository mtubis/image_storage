<?php

declare(strict_types=1);

use App\Actions\Images\DeleteImage;
use App\Models\Image;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToDeleteFile;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    fake_image_storage();
});

it('removes the record and both files', function (): void {
    $image = stored_image('valid.jpg');

    resolve(DeleteImage::class)->handle($image);

    expect(Image::query()->count())->toBe(0)
        ->and(Storage::disk('originals')->allFiles())->toBe([])
        ->and(Storage::disk('thumbnails')->allFiles())->toBe([]);
});

it('leaves other images and their files alone', function (): void {
    $image = stored_image('valid.jpg');
    $other = stored_image('valid.png');

    resolve(DeleteImage::class)->handle($image);

    expect(Image::query()->sole()->is($other))->toBeTrue()
        ->and(Storage::disk('originals')->allFiles())->toBe([$other->original_path])
        ->and(Storage::disk('thumbnails')->allFiles())->toBe([$other->thumbnail_path]);
});

it('removes the files only once the surrounding transaction commits', function (): void {
    $image = stored_image('valid.jpg');

    DB::transaction(function () use ($image): void {
        resolve(DeleteImage::class)->handle($image);

        // A rollback from here on would restore the row, so its files must still be there.
        expect(Storage::disk('originals')->exists($image->original_path))->toBeTrue()
            ->and(Storage::disk('thumbnails')->exists($image->thumbnail_path))->toBeTrue();
    });

    expect(Storage::disk('originals')->exists($image->original_path))->toBeFalse()
        ->and(Storage::disk('thumbnails')->exists($image->thumbnail_path))->toBeFalse();
});

it('keeps the files when the surrounding transaction rolls back', function (): void {
    $image = stored_image('valid.jpg');

    expect(fn () => DB::transaction(function () use ($image): never {
        resolve(DeleteImage::class)->handle($image);

        throw new RuntimeException('Rolled back.');
    }))->toThrow(RuntimeException::class, 'Rolled back.');

    expect(Image::query()->sole()->is($image))->toBeTrue()
        ->and(Storage::disk('originals')->exists($image->original_path))->toBeTrue()
        ->and(Storage::disk('thumbnails')->exists($image->thumbnail_path))->toBeTrue();
});

// The row is gone by then, so the deletion stands: an orphaned file is only reported.
it('reports a file that cannot be removed and still removes the other one', function (string $failingDisk, string $otherDisk): void {
    Exceptions::fake();
    $image = stored_image('valid.jpg');
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->once()->andThrow(UnableToDeleteFile::atLocation('any', 'Disk is gone.'));
    Storage::set($failingDisk, $disk);

    resolve(DeleteImage::class)->handle($image);

    expect(Image::query()->count())->toBe(0)
        ->and(Storage::disk($otherDisk)->allFiles())->toBe([]);
    Exceptions::assertReported(UnableToDeleteFile::class);
})->with([
    'original' => ['originals', 'thumbnails'],
    'thumbnail' => ['thumbnails', 'originals'],
]);

it('keeps both files when the record is not deleted', function (): void {
    $image = stored_image('valid.jpg');
    Image::deleting(fn (): bool => false);

    expect(fn () => resolve(DeleteImage::class)->handle($image))->toThrow(RuntimeException::class, "Image [{$image->id}] was not deleted.")
        ->and(Image::query()->sole()->is($image))->toBeTrue()
        ->and(Storage::disk('originals')->exists($image->original_path))->toBeTrue()
        ->and(Storage::disk('thumbnails')->exists($image->thumbnail_path))->toBeTrue();
});

it('removes a record whose files are already missing', function (): void {
    Exceptions::fake();
    $image = Image::factory()->create();

    resolve(DeleteImage::class)->handle($image);

    expect(Image::query()->count())->toBe(0);
    Exceptions::assertNothingReported();
});
