<?php

declare(strict_types=1);

namespace App\Services\Images;

use App\Contracts\ThumbnailGenerator;
use App\Exceptions\ThumbnailGenerationFailed;
use finfo;
use Imagick;
use ImagickException;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Exceptions\ImageException;
use Intervention\Image\ImageManager;
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

    public function generate(string $contents): string
    {
        $coder = $this->coderFor($contents);

        // readImageBlob() ignores the "[0]" page selector, so the bytes go through a file.
        $file = tmpfile();
        $path = $file === false ? null : (stream_get_meta_data($file)['uri'] ?? null);

        if ($file === false || $path === null || fwrite($file, $contents) !== strlen($contents)) {
            throw new ThumbnailGenerationFailed('Cannot buffer the image in a temporary file.');
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
            $this->scaleDown($image);

            return $this->manager->decode($image)
                ->encode(new WebpEncoder($this->quality))
                ->toString();
        } catch (ImageException|ImagickException $e) {
            throw ThumbnailGenerationFailed::because($e);
        } finally {
            fclose($file);
        }
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
