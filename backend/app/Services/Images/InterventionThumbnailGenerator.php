<?php

declare(strict_types=1);

namespace App\Services\Images;

use App\Contracts\ThumbnailGenerator;
use App\Data\Thumbnail;
use App\Exceptions\ThumbnailGenerationFailed;
use finfo;
use Imagick;
use ImagickException;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;

/**
 * Imagick-backed: GD cannot read TIFF. Resource limits are applied process-wide at boot
 * (AppServiceProvider), so an oversized image fails inside ImageMagick and surfaces here.
 *
 * Decoding and the first downscale use Imagick directly; Intervention then orients, strips
 * and encodes. Intervention alone would decode every page with an auto-detected coder and
 * coalesce (clone) the full-size pixels, several copies of up to ~800 MB each.
 */
final readonly class InterventionThumbnailGenerator implements ThumbnailGenerator
{
    // ImageMagick coder per detected extension; anything else must never reach ImageMagick.
    private const array CODERS = [
        'jpg' => 'JPEG',
        'jpeg' => 'JPEG',
        'png' => 'PNG',
        'webp' => 'WEBP',
        'tif' => 'TIFF',
        'tiff' => 'TIFF',
        'bmp' => 'BMP',
    ];

    // EXIF orientations 5–8 rotate the image by 90°, so its displayed edges are swapped.
    private const array TRANSPOSING_ORIENTATIONS = [
        Imagick::ORIENTATION_LEFTTOP,
        Imagick::ORIENTATION_RIGHTTOP,
        Imagick::ORIENTATION_RIGHTBOTTOM,
        Imagick::ORIENTATION_LEFTBOTTOM,
    ];

    private ImageManager $manager;

    /**
     * @param  list<string>  $allowedExtensions  as accepted by upload validation
     */
    public function __construct(
        private int $maxEdge,
        private int $quality,
        private array $allowedExtensions,
    ) {
        $this->manager = new ImageManager(
            Driver::class,
            autoOrientation: true,
            // Thumbnails are public; EXIF (e.g. GPS), IPTC and XMP stay with the private original.
            // The ICC profile is kept, so colours of non-sRGB images are still rendered correctly.
            strip: true,
        );
    }

    public function generate(string $contents): Thumbnail
    {
        $coder = $this->coderFor($contents);
        [$storedWidth, $storedHeight] = $this->storedSize($contents);

        // readImageBlob() ignores the "[0]" page selector, so the bytes go through a file.
        $file = tmpfile();
        $path = $file === false ? null : (stream_get_meta_data($file)['uri'] ?? null);

        if ($file === false || $path === null || fwrite($file, $contents) !== strlen($contents)) {
            // Not ThumbnailGenerationFailed: that means "bad image" (a 422), this is a server fault.
            throw new RuntimeException('Cannot buffer the image in a temporary file.');
        }

        try {
            $image = new Imagick;

            if ($coder === 'JPEG') {
                // libjpeg can decode at 1/2 to 1/8 scale directly; keep 2× headroom for quality.
                $image->setOption('jpeg:size', ($this->maxEdge * 2).'x'.($this->maxEdge * 2));
            }

            // The coder prefix stops ImageMagick from sniffing a different format out of a
            // polyglot, and "[0]" decodes only the first page: validation measured only that one.
            $image->readImage($coder.':'.$path.'[0]');
            // Read before Intervention orients the image, which resets it to "top-left".
            $transposed = in_array($image->getImageOrientation(), self::TRANSPOSING_ORIENTATIONS, true);
            $this->scaleDown($image);
        } catch (ImagickException $e) {
            // Decoding (and the resource limits it hits) is where a file shows it's damaged.
            throw ThumbnailGenerationFailed::because($e);
        } finally {
            fclose($file);
        }

        // Past the decode, the image is small and valid: a failure from here on (a missing WebP
        // delegate, a policy, configuration) is the server's, so it isn't mapped to a 422.
        return new Thumbnail(
            contents: $this->manager->decode($image)->encode(new WebpEncoder($this->quality))->toString(),
            sourceWidth: $transposed ? $storedHeight : $storedWidth,
            sourceHeight: $transposed ? $storedWidth : $storedHeight,
        );
    }

    /**
     * Same detection as Laravel's "mimes" rule: the first extension Symfony maps to the
     * content-sniffed MIME type. This also resolves aliases such as image/x-ms-bmp.
     */
    private function coderFor(string $contents): string
    {
        $mimeType = (string) new finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        $extension = MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? null;

        if ($extension === null || ! in_array($extension, $this->allowedExtensions, true) || ! isset(self::CODERS[$extension])) {
            throw ThumbnailGenerationFailed::unsupportedType($mimeType);
        }

        return self::CODERS[$extension];
    }

    /**
     * Size as stored, from the header of the first page: the same numbers the upload's
     * dimensions rule checked. Not Imagick's, because JPEG shrink-on-load changes those.
     *
     * @return array{int, int}
     */
    private function storedSize(string $contents): array
    {
        $size = getimagesizefromstring($contents);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw ThumbnailGenerationFailed::unreadableSize();
        }

        return [$size[0], $size[1]];
    }

    /**
     * The bounding box is square, so scaling before EXIF orientation is applied gives the
     * same result as after it. Never upscales.
     */
    private function scaleDown(Imagick $image): void
    {
        if ($image->getImageWidth() <= $this->maxEdge && $image->getImageHeight() <= $this->maxEdge) {
            return;
        }

        $image->thumbnailImage($this->maxEdge, $this->maxEdge, bestfit: true);
    }
}
