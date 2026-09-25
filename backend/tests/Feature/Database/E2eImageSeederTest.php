<?php

declare(strict_types=1);

use App\Models\Image;
use Database\Seeders\E2eImageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    fake_image_storage();
    // The weather job is the upload's concern, not the seeder's.
    Bus::fake();
});

it('seeds more images than fit on one page, so the list has a next page', function (): void {
    $this->seed(E2eImageSeeder::class);

    expect(Image::query()->count())
        ->toBe(E2eImageSeeder::COUNT)
        ->toBeGreaterThan(config()->integer('images.page_size'));
});

it('stores every image like an upload, with its original and thumbnail on disk', function (): void {
    $this->seed(E2eImageSeeder::class);

    // each() alone would pass on an empty table.
    expect(Image::query()->count())->toBe(E2eImageSeeder::COUNT);
    Image::query()->each(function (Image $image): void {
        expect($image->mime_type)->toBe('image/jpeg')
            ->and($image->extension)->toBe('jpg')
            ->and($image->size_bytes)->toBe(Storage::disk('originals')->size($image->original_path));
        Storage::disk('thumbnails')->assertExists($image->thumbnail_path);
    });
});

it('seeds uniquely named images in both orientations', function (): void {
    $this->seed(E2eImageSeeder::class);

    $images = Image::query()->get();

    expect($images->pluck('original_name')->unique())->toHaveCount(E2eImageSeeder::COUNT)
        ->and($images->contains(fn (Image $image): bool => $image->width > $image->height))->toBeTrue()
        ->and($images->contains(fn (Image $image): bool => $image->height > $image->width))->toBeTrue();
});

it('seeds images that pass the upload constraints', function (): void {
    $this->seed(E2eImageSeeder::class);

    // each() alone would pass on an empty table.
    expect(Image::query()->count())->toBe(E2eImageSeeder::COUNT);
    Image::query()->each(function (Image $image): void {
        expect($image->width)->toBeGreaterThanOrEqual(config()->integer('images.min_width'))
            ->and($image->height)->toBeGreaterThanOrEqual(config()->integer('images.min_height'))
            ->and($image->size_bytes)->toBeLessThanOrEqual(config()->integer('images.max_size_kb') * 1024);
    });
});

// The E2E list scenarios rely on seed-15 being the newest and seed-01 the oldest. The wall clock
// can step back while seeding (seen on WSL 2 after a time sync), which reordered the list.
it('orders the seeded images by number even when the clock steps back while seeding', function (): void {
    $this->freezeTime();
    Image::creating(function (): void {
        $this->travel(-1)->seconds();
    });

    $this->seed(E2eImageSeeder::class);

    $newestFirst = array_map(
        static fn (int $number): string => sprintf('seed-%02d.jpg', $number),
        range(E2eImageSeeder::COUNT, 1),
    );
    expect(Image::forListing()->pluck('original_name')->all())->toBe($newestFirst)
        // In the past: whatever the E2E tests upload afterwards is newer.
        ->and(Image::query()->latest()->firstOrFail()->created_at?->lessThanOrEqualTo(now()))->toBeTrue();
});
