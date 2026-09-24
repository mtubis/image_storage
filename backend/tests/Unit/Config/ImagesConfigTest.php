<?php

declare(strict_types=1);

// Pins config/images.php to the assignment's upload constraints, so a careless edit
// of a limit fails loudly instead of silently changing validation.
it('matches the assignment upload constraints', function (): void {
    expect(config('images.max_size_kb'))->toBe(5120)
        ->and(config('images.min_width'))->toBe(500)
        ->and(config('images.min_height'))->toBe(500)
        ->and(config('images.page_size'))->toBe(10)
        ->and(config('images.thumbnail_max_edge'))->toBe(400);
});

it('allows exactly JPG, PNG, WebP, TIFF and BMP', function (): void {
    expect(config('images.allowed_types'))->toBe([
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/tiff' => ['tif', 'tiff'],
        'image/bmp' => ['bmp'],
    ]);
});

// Laravel 13 ships its own config/images.php (Illuminate\Image, default driver "gd") and
// merges it into ours; GD cannot read TIFF, so the key is owned explicitly.
it('selects the imagick driver for the framework image component', function (): void {
    expect(config('images.default'))->toBe('imagick');
});
