<?php

declare(strict_types=1);

namespace App\Services\Images;

/**
 * Finds the EXIF block in PNG and WebP containers without decoding any pixels.
 *
 * ext-exif can't read these formats, and Imagick's pingImage() doesn't expose the EXIF
 * profile of a WebP (only a full, pixel-decoding read does). Both containers are plain
 * chunk lists, and the EXIF payload is a TIFF structure ext-exif can parse on its own.
 * Every length is checked against the data, so damaged or hostile input yields null.
 */
final readonly class ExifChunkReader
{
    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1A\n";

    // Not part of either spec, but some writers keep the JPEG APP1 identifier.
    private const string APP1_IDENTIFIER = "Exif\x00\x00";

    /**
     * PNG chunk: length (uint32 BE), type, data, CRC. The spec asks for eXIf before IDAT,
     * but writers disagree, so the whole file is scanned.
     */
    public function fromPng(string $png): ?string
    {
        if (! str_starts_with($png, self::PNG_SIGNATURE)) {
            return null;
        }

        $size = strlen($png);
        $offset = strlen(self::PNG_SIGNATURE);

        while ($offset + 8 <= $size) {
            /** @var array{length: int, type: string} $header */
            $header = unpack('Nlength/a4type', $png, $offset);
            $dataOffset = $offset + 8;

            if ($header['length'] > $size - $dataOffset) {
                return null;
            }

            if ($header['type'] === 'eXIf') {
                return $this->withoutApp1Identifier(substr($png, $dataOffset, $header['length']));
            }

            $offset = $dataOffset + $header['length'] + 4;
        }

        return null;
    }

    /**
     * RIFF chunk: FourCC, size (uint32 LE), data, padded to an even size.
     */
    public function fromWebp(string $webp): ?string
    {
        if (strlen($webp) < 12 || ! str_starts_with($webp, 'RIFF') || substr($webp, 8, 4) !== 'WEBP') {
            return null;
        }

        $size = strlen($webp);
        $offset = 12;

        while ($offset + 8 <= $size) {
            /** @var array{type: string, length: int} $header */
            $header = unpack('a4type/Vlength', $webp, $offset);
            $dataOffset = $offset + 8;

            if ($header['length'] > $size - $dataOffset) {
                return null;
            }

            if ($header['type'] === 'EXIF') {
                return $this->withoutApp1Identifier(substr($webp, $dataOffset, $header['length']));
            }

            $offset = $dataOffset + $header['length'] + ($header['length'] % 2);
        }

        return null;
    }

    private function withoutApp1Identifier(string $exif): string
    {
        return str_starts_with($exif, self::APP1_IDENTIFIER) ? substr($exif, strlen(self::APP1_IDENTIFIER)) : $exif;
    }
}
