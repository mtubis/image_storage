<?php

declare(strict_types=1);

// Single source of truth for upload constraints, thumbnails and listing.
return [

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

    // WebP quality (1–100) of the generated thumbnail.
    'thumbnail_quality' => 80,

    // Process-wide ImageMagick limits, in bytes, applied at boot together with max_width /
    // max_height as width/height limits. Memory and map alone only make ImageMagick spill its
    // pixel cache to disk; the disk limit is what finally rejects an oversized image. The sum
    // fits one full-size copy of a 10000×10000 image (~800 MB at 16 bits per RGBA channel),
    // which is all the thumbnail generator keeps; verified for JPEG, PNG, WebP and TIFF.
    'imagick_limits' => [
        'memory' => 256 * 1024 ** 2,
        'map' => 512 * 1024 ** 2,
        'disk' => 1024 ** 3,
    ],

    // Upper bound for the metadata JSON of one image, in bytes. Real camera metadata stays in
    // the hundreds of KB, but ext-exif turns a crafted numeric array into text ~3.4× the size
    // of the file ("-2147483648/-2147483648" from 8 bytes): 17.7 MB for a 5 MB TIFF, beyond
    // MariaDB's default max_allowed_packet of 16 MiB (the whole INSERT must fit into it).
    'max_metadata_bytes' => 8 * 1024 ** 2,

    'page_size' => 10,

    // Uploads per minute and client IP address. Each can cost seconds of CPU and hundreds of
    // MB of pixel cache; generous for a person, a brake on a script. Raised by the E2E stack,
    // whose tests all upload from one address (e.g. make e2e args="--repeat-each=10").
    'uploads_per_minute' => (int) env('UPLOADS_PER_MINUTE', 30),

];
