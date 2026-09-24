<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Rejects a JPEG whose compressed image data does not end with the EOI marker, i.e. a
 * truncated upload. libjpeg only warns about a missing tail and pads the missing rows with
 * grey, so the thumbnail generator would accept it; PNG, WebP, TIFF and BMP decoders fail.
 *
 * Header segments are walked by their declared lengths, because they may contain any bytes
 * (an EXIF thumbnail has its own EOI). Inside entropy-coded data a 0xFF byte is always
 * followed by 0x00 or a restart marker, so the first FF D9 after the first scan header can
 * only be the real EOI; progressive JPEGs keep their later scans and tables before it.
 */
final readonly class CompleteJpeg implements ValidationRule
{
    // Also used for a damaged file only the thumbnail decoder notices (ImageController).
    public const string MESSAGE = 'The :attribute is incomplete or damaged.';

    private const string START_OF_IMAGE = "\xFF\xD8";

    private const string END_OF_IMAGE = "\xFF\xD9";

    private const int START_OF_SCAN = 0xDA;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Presence and type are the job of the other rules; only a JPEG is inspected.
        if (! $value instanceof UploadedFile || $value->getMimeType() !== 'image/jpeg') {
            return;
        }

        if (! $this->isComplete($value->getContent())) {
            $fail(self::MESSAGE);
        }
    }

    private function isComplete(string $jpeg): bool
    {
        $scanData = $this->scanDataOffset($jpeg);

        return $scanData !== null && str_contains(substr($jpeg, $scanData), self::END_OF_IMAGE);
    }

    /**
     * Offset right after the first scan header, or null when the headers end before it.
     */
    private function scanDataOffset(string $jpeg): ?int
    {
        if (! str_starts_with($jpeg, self::START_OF_IMAGE)) {
            return null;
        }

        $length = strlen($jpeg);
        $offset = strlen(self::START_OF_IMAGE);

        while ($offset + 4 <= $length) {
            if ($jpeg[$offset] !== "\xFF") {
                return null;
            }

            $marker = ord($jpeg[$offset + 1]);

            // Fill bytes: any number of 0xFF may precede a marker.
            if ($marker === 0xFF) {
                $offset++;

                continue;
            }

            // TEM and RSTn stand alone, without a length field.
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $offset += 2;

                continue;
            }

            // Big-endian length that counts its own two bytes, but not the marker.
            $segmentEnd = $offset + 2 + (ord($jpeg[$offset + 2]) << 8 | ord($jpeg[$offset + 3]));

            if ($marker === self::START_OF_SCAN) {
                return $segmentEnd <= $length ? $segmentEnd : null;
            }

            // SOI again, EOI before any scan, or a nonsensical length.
            if ($marker === 0xD8 || $marker === 0xD9 || $segmentEnd <= $offset + 3) {
                return null;
            }

            $offset = $segmentEnd;
        }

        return null;
    }
}
