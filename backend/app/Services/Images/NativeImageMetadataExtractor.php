<?php

declare(strict_types=1);

namespace App\Services\Images;

use App\Contracts\ImageMetadataExtractor;
use App\Data\ImageMetadata;
use finfo;

/**
 * EXIF via ext-exif, IPTC via iptcparse(); see tests/Fixtures/README.md for what PHP reads
 * per format. ext-exif reads JPEG and TIFF itself; for PNG and WebP the embedded EXIF block
 * (a TIFF structure) is cut out of the container and parsed the same way, so all formats
 * produce the same shape. BMP has no standard place for either.
 */
final readonly class NativeImageMetadataExtractor implements ImageMetadataExtractor
{
    // Derived by PHP (file name, upload time, HTML size attributes...), not read from the image.
    private const array COMPUTED_SECTIONS = ['FILE', 'COMPUTED'];

    // TIFF tag 33723: a raw IPTC-IIM block, exposed as parsed IPTC instead.
    private const string IPTC_TAG = 'IPTC/NAA';

    public function __construct(
        private ExifChunkReader $chunkReader,
        private MetadataSanitizer $sanitizer,
    ) {}

    public function extract(string $contents): ImageMetadata
    {
        // Detected here again rather than passed in, so the contract stays "bytes in" and a
        // caller can't make the extractor parse a file as something it is not.
        $mimeType = new finfo(FILEINFO_MIME_TYPE)->buffer($contents);

        $exif = $this->readExif(match ($mimeType) {
            'image/jpeg', 'image/tiff' => $contents,
            'image/png' => $this->chunkReader->fromPng($contents),
            'image/webp' => $this->chunkReader->fromWebp($contents),
            default => null,
        });

        // JPEG keeps IPTC in APP13; TIFF (and the odd JPEG converted from one) in tag 33723.
        $iptcBlock = $mimeType === 'image/jpeg' ? $this->app13Segment($contents) : null;
        $iptcFromTag = $iptcBlock === null;
        $iptcBlock ??= $this->iptcTagBytes($exif);

        return new ImageMetadata(
            exif: $this->sanitizeSections($this->withoutDerivedData($exif, $iptcFromTag)),
            iptc: $this->sanitizeIptc($this->parseIptc($iptcBlock)),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readExif(?string $contents): array
    {
        if ($contents === null || $contents === '') {
            return [];
        }

        $stream = fopen('php://memory', 'r+b');

        if ($stream === false) {
            return [];
        }

        fwrite($stream, $contents);
        rewind($stream);

        // Damaged EXIF is common. ext-exif warns and still returns what it could read;
        // unmuted, Laravel would turn the warning into an exception and lose it all.
        $exif = $this->muted(static fn (): array|false => exif_read_data($stream, null, true, false));
        fclose($stream);

        return is_array($exif) ? $exif : [];
    }

    /**
     * PHP keeps only the first APP13 segment, so an IPTC record split across several
     * (over ~64 KB, very rare) is truncated to the datasets in the first one.
     */
    private function app13Segment(string $jpeg): ?string
    {
        return $this->muted(static function () use ($jpeg): ?string {
            getimagesizefromstring($jpeg, $info);
            $app13 = is_array($info) ? ($info['APP13'] ?? null) : null;

            return is_string($app13) ? $app13 : null;
        });
    }

    /**
     * Written as UNDEFINED/BYTE the tag comes back as a string. Photoshop writes it as LONG,
     * which ext-exif returns as integers already decoded in the file's byte order, so they
     * are packed back the same way to restore the original bytes.
     *
     * @param  array<array-key, mixed>  $exif
     */
    private function iptcTagBytes(array $exif): ?string
    {
        $value = is_array($exif['IFD0'] ?? null) ? ($exif['IFD0'][self::IPTC_TAG] ?? null) : null;

        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value) || $value === [] || array_filter($value, is_int(...)) !== $value) {
            return null;
        }

        $bigEndian = is_array($exif['COMPUTED'] ?? null) && ($exif['COMPUTED']['ByteOrderMotorola'] ?? 0) === 1;

        return pack($bigEndian ? 'N*' : 'V*', ...$value);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function parseIptc(?string $block): array
    {
        if ($block === null || $block === '') {
            return [];
        }

        $iptc = $this->muted(static fn (): array|false => iptcparse($block));

        return is_array($iptc) ? $iptc : [];
    }

    /**
     * @param  array<array-key, mixed>  $exif
     * @param  bool  $iptcFromTag  the IPTC tag was the IPTC source, so it is already kept, parsed
     * @return array<array-key, mixed>
     */
    private function withoutDerivedData(array $exif, bool $iptcFromTag): array
    {
        $exif = array_diff_key($exif, array_flip(self::COMPUTED_SECTIONS));

        if ($iptcFromTag && is_array($exif['IFD0'] ?? null)) {
            unset($exif['IFD0'][self::IPTC_TAG]);
        }

        return $exif;
    }

    /**
     * @param  array<array-key, mixed>  $exif
     * @return array<string, array<array-key, mixed>>
     */
    private function sanitizeSections(array $exif): array
    {
        $sections = [];

        foreach ($exif as $name => $tags) {
            // With $as_arrays every section is an array; an emptied IFD0 is dropped.
            if (is_array($tags) && $tags !== []) {
                $sections[(string) $name] = $this->sanitizer->sanitize($tags);
            }
        }

        return $sections;
    }

    /**
     * @param  array<array-key, mixed>  $iptc
     * @return array<string, list<mixed>>
     */
    private function sanitizeIptc(array $iptc): array
    {
        $datasets = [];

        foreach ($iptc as $dataset => $values) {
            if (is_array($values)) {
                $datasets[(string) $dataset] = array_values($this->sanitizer->sanitize($values));
            }
        }

        return $datasets;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function muted(callable $callback): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
