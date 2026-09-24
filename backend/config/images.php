<?php

declare(strict_types=1);

// Single source of truth for upload constraints, thumbnails and listing.
return [

    // Laravel 13 ships its own config/images.php for Illuminate\Image and merges it with
    // this file, so its "default" key is owned here explicitly: GD cannot read TIFF.
    'default' => 'imagick',

    // Detected MIME type => accepted file extensions. Validation rules, thumbnails and the
    // stored extension all derive from this one map.
    'allowed_types' => [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/tiff' => ['tif', 'tiff'],
        'image/bmp' => ['bmp'],
    ],

    // KiB, the unit of Laravel's "max" file rule: 5 MB = 5120 KiB.
    'max_size_kb' => 5120,

    'min_width' => 500,
    'min_height' => 500,

    // Upper bound against decompression bombs: a flat 30000×30000 PNG fits in 5 MB but
    // decodes to gigabytes of pixels when the thumbnail is generated. Not an assignment
    // requirement; generous enough for any real camera or scanner output.
    'max_width' => 10000,
    'max_height' => 10000,

    // Longest edge of the generated WebP thumbnail, in pixels.
    'thumbnail_max_edge' => 400,

    'page_size' => 10,

];
