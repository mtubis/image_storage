<?php

declare(strict_types=1);

// Validation bounds only the first TIFF page and not the pixel area, so ImageMagick itself
// must refuse oversized input instead of spilling gigabytes to its disk cache.
it('applies the configured ImageMagick resource limits at boot', function (): void {
    // getResourceLimit() returns a float.
    $limit = static fn (int $type): int => (int) Imagick::getResourceLimit($type);

    expect($limit(Imagick::RESOURCETYPE_WIDTH))->toBe(config('images.max_width'))
        ->and($limit(Imagick::RESOURCETYPE_HEIGHT))->toBe(config('images.max_height'))
        ->and($limit(Imagick::RESOURCETYPE_MEMORY))->toBe(config('images.imagick_limits.memory'))
        ->and($limit(Imagick::RESOURCETYPE_MAP))->toBe(config('images.imagick_limits.map'))
        ->and($limit(Imagick::RESOURCETYPE_DISK))->toBe(config('images.imagick_limits.disk'));
});
