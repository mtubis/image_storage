<?php

declare(strict_types=1);

use App\Contracts\ThumbnailGenerator;
use App\Exceptions\ThumbnailGenerationFailed;
use App\Services\Images\InterventionThumbnailGenerator;

function thumbnail_of(string $contents): Imagick
{
    $thumbnail = new Imagick;
    $thumbnail->readImageBlob(resolve(ThumbnailGenerator::class)->generate($contents));

    return $thumbnail;
}

function blank_image(int $width, int $height, string $background, string $format): string
{
    $image = new Imagick;
    $image->newImage($width, $height, $background, $format);

    return $image->getImageBlob();
}

/**
 * ICC profile shipped with Ghostscript in the php image; used only to build test input.
 */
function icc_profile(string $name): string
{
    $path = "/usr/share/color/icc/ghostscript/{$name}";

    if (! is_file($path)) {
        throw new RuntimeException("ICC profile [{$path}] not found in the php image.");
    }

    return (string) file_get_contents($path);
}

/**
 * Runs the callback with ImageMagick's pixel-cache limits (memory, map, disk) set to the
 * given bytes each; they are process-wide, so leaking them would break later tests.
 */
function with_pixel_cache_limit(int $bytes, Closure $callback): mixed
{
    $types = [Imagick::RESOURCETYPE_MEMORY, Imagick::RESOURCETYPE_MAP, Imagick::RESOURCETYPE_DISK];
    $previous = array_map(Imagick::getResourceLimit(...), $types);

    try {
        foreach ($types as $type) {
            Imagick::setResourceLimit($type, $bytes);
        }

        return $callback();
    } finally {
        foreach ($types as $index => $type) {
            Imagick::setResourceLimit($type, (int) $previous[$index]);
        }
    }
}

it('is bound to the Intervention implementation', function (): void {
    expect(resolve(ThumbnailGenerator::class))->toBeInstanceOf(InterventionThumbnailGenerator::class);
});

it('produces a single-frame WebP scaled down to the maximum edge', function (string $name): void {
    // Not thumbnail_of(): the raw bytes are needed for content sniffing.
    $webp = resolve(ThumbnailGenerator::class)->generate(fixture_contents($name));
    $thumbnail = new Imagick;
    $thumbnail->readImageBlob($webp);

    expect(new finfo(FILEINFO_MIME_TYPE)->buffer($webp))->toBe('image/webp')
        ->and($thumbnail->getNumberImages())->toBe(1)
        ->and([$thumbnail->getImageWidth(), $thumbnail->getImageHeight()])->toBe([400, 400]);
})->with(['valid.jpg', 'valid.png', 'valid.webp', 'valid.tiff', 'valid.bmp']);

it('keeps the aspect ratio', function (): void {
    $thumbnail = thumbnail_of(blank_image(1000, 600, 'white', 'png'));

    expect([$thumbnail->getImageWidth(), $thumbnail->getImageHeight()])->toBe([400, 240]);
});

it('never upscales a smaller image', function (): void {
    $thumbnail = thumbnail_of(blank_image(200, 100, 'white', 'png'));

    expect([$thumbnail->getImageWidth(), $thumbnail->getImageHeight()])->toBe([200, 100]);
});

// exif-iptc.jpg is stored 640×500 with Orientation 6 (rotate 90° clockwise to display), and
// its gradient runs from blue at the stored top to orange at the stored bottom.
it('applies the EXIF orientation', function (): void {
    $thumbnail = thumbnail_of(fixture_contents('exif-iptc.jpg'));
    $width = $thumbnail->getImageWidth();
    $height = $thumbnail->getImageHeight();
    $left = $thumbnail->getImagePixelColor(2, intdiv($height, 2))->getColor();
    $right = $thumbnail->getImagePixelColor($width - 3, intdiv($height, 2))->getColor();

    // 500×640 displayed, scaled to a 400 px long edge: 312.5 px wide.
    expect([$width, $height])->toBe([313, 400])
        ->and($right['b'])->toBeGreaterThan($right['r'])
        ->and($left['r'])->toBeGreaterThan($left['b']);
});

// Thumbnails are public: camera EXIF (possibly GPS), IPTC or XMP must not leak through them.
it('strips metadata from the thumbnail', function (string $contents): void {
    expect(thumbnail_of($contents)->getImageProfiles('*', false))->toBe([]);
})->with([
    'exif-iptc.jpg' => fn (): string => fixture_contents('exif-iptc.jpg'),
    'exif-iptc.tiff' => fn (): string => fixture_contents('exif-iptc.tiff'),
    'exif.png' => fn (): string => fixture_contents('exif.png'),
    'exif.webp' => fn (): string => fixture_contents('exif.webp'),
    // Downscaling drops profiles by itself; an image that needs none must be stripped too.
    'small PNG with EXIF' => function (): string {
        $image = new Imagick;
        $image->newImage(300, 300, 'white', 'png');
        $image->setImageProfile('exif', new Imagick(fixture_path('exif.png'))->getImageProfile('exif'));

        return $image->getImageBlob();
    },
]);

it('keeps the ICC colour profile', function (): void {
    $image = new Imagick;
    $image->newImage(600, 600, '#e03030', 'png');
    $image->profileImage('icc', icc_profile('a98.icc'));

    expect(thumbnail_of($image->getImageBlob())->getImageProfiles('*', false))->toBe(['icc']);
});

// Pinned, not endorsed: a CMYK source is converted to sRGB without its ICC profile, so colours
// shift (#e03030 comes out close to #ed0002). The CMYK profile must not stay attached to RGB
// pixels. ICC-managed conversion needs a bundled sRGB profile (see PLAN.md Decision log).
it('converts a CMYK source to sRGB and drops its CMYK profile', function (): void {
    $image = new Imagick;
    $image->newImage(600, 600, '#e03030', 'jpeg');
    $image->profileImage('icc', icc_profile('srgb.icc'));
    $image->profileImage('icc', icc_profile('default_cmyk.icc'));
    $thumbnail = thumbnail_of($image->getImageBlob());
    $pixel = $thumbnail->getImagePixelColor(10, 10)->getColor();

    expect($image->getImageColorspace())->toBe(Imagick::COLORSPACE_CMYK)
        ->and($thumbnail->getImageColorspace())->toBe(Imagick::COLORSPACE_SRGB)
        ->and($thumbnail->getImageProfiles('*', false))->toBe([])
        ->and($pixel['r'])->toBeGreaterThan(200)
        ->and($pixel['g'])->toBeLessThan(60)
        ->and($pixel['b'])->toBeLessThan(60);
});

// Validation measures only the first page; the other pages are never shown or measured.
it('uses only the first page of a multi-page TIFF', function (): void {
    $thumbnail = thumbnail_of(fixture_contents('multipage.tiff'));

    expect($thumbnail->getNumberImages())->toBe(1)
        ->and([$thumbnail->getImageWidth(), $thumbnail->getImageHeight()])->toBe([400, 400]);
});

// Semi-transparent, so flattening onto a background or dropping alpha levels is caught too.
it('keeps transparency', function (): void {
    $thumbnail = thumbnail_of(blank_image(600, 600, 'rgba(255, 0, 0, 0.5)', 'png'));

    expect($thumbnail->getImagePixelColor(10, 10)->getColorValue(Imagick::COLOR_ALPHA))->toEqualWithDelta(0.5, 0.01);
});

it('rejects input that is not a decodable image', function (string $contents): void {
    resolve(ThumbnailGenerator::class)->generate($contents);
})->with([
    'empty' => '',
    'plain text' => 'not an image',
    'truncated PNG' => fn (): string => substr(fixture_contents('valid.png'), 0, 1000),
])->throws(ThumbnailGenerationFailed::class);

// libjpeg reports a missing tail only as a warning and pads the missing rows with grey, like
// browsers do; PHP Imagick throws on errors only and exposes no warnings. Pinned so that a
// change of this behaviour is noticed: rejecting such files needs a structural check.
it('renders a truncated JPEG leniently instead of failing', function (): void {
    $jpeg = fixture_contents('valid.jpg');
    $thumbnail = thumbnail_of(substr($jpeg, 0, intdiv(strlen($jpeg), 2)));

    expect([$thumbnail->getImageWidth(), $thumbnail->getImageHeight()])->toBe([400, 400]);
});

// ImageMagick decodes far more than the allowed formats (GIF, PDF via Ghostscript, SVG…),
// choosing the coder from the content; only the allowed types may reach it.
it('rejects formats outside the allowed types', function (string $name): void {
    resolve(ThumbnailGenerator::class)->generate(fixture_contents($name));
})->with(['not-allowed.gif', 'not-an-image.pdf'])->throws(ThumbnailGenerationFailed::class);

// Calibrated: a 2000×2000 gradient needs ~24 MB of pixel cache (16-bit RGB). Decoding with
// Intervention alone kept several full-size copies and failed at this limit; the maximum
// validated 10000×10000 image scales the same way against the configured limits.
it('decodes with a single full-size pixel cache', function (string $format): void {
    $image = new Imagick;
    $image->newPseudoImage(2000, 2000, 'gradient:#3a6ea5-#f4a261');
    $image->setImageFormat($format);
    $contents = $image->getImageBlob();
    // The source's own pixels count against the same process-wide limits.
    $image->clear();

    $thumbnail = with_pixel_cache_limit(24 * 1024 ** 2, fn (): string => resolve(ThumbnailGenerator::class)->generate($contents));

    expect(getimagesizefromstring($thumbnail))->toMatchArray([0 => 400, 1 => 400]);
})->with(['png', 'webp']);

// Upload validation is the source of truth: a type it no longer accepts is refused here too,
// even though ImageMagick could decode it.
it('rejects a decodable type that validation does not allow', function (): void {
    new InterventionThumbnailGenerator(maxEdge: 400, quality: 80, allowedExtensions: ['png'])
        ->generate(fixture_contents('valid.jpg'));
})->throws(ThumbnailGenerationFailed::class, 'image/jpeg');

it('fails with a typed exception when the pixel cache limits are exceeded', function (): void {
    $image = new Imagick;
    $image->newPseudoImage(2000, 2000, 'gradient:#3a6ea5-#f4a261');
    $image->setImageFormat('png');
    $contents = $image->getImageBlob();

    with_pixel_cache_limit(8 * 1024 ** 2, fn (): string => resolve(ThumbnailGenerator::class)->generate($contents));
})->throws(ThumbnailGenerationFailed::class);
