<?php

declare(strict_types=1);

namespace App\Data;

/**
 * EXIF and IPTC of one image. Values are JSON-safe: anything that was not valid UTF-8 is
 * wrapped as {"base64": "..."} by MetadataSanitizer.
 */
final readonly class ImageMetadata
{
    /**
     * @param  array<string, array<array-key, mixed>>  $exif  ext-exif sections (IFD0, EXIF, GPS, ...) => tags
     * @param  array<string, list<mixed>>  $iptc  IPTC-IIM dataset ("record#dataset", e.g. "2#025") => values
     */
    public function __construct(
        public array $exif = [],
        public array $iptc = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->exif === [] && $this->iptc === [];
    }

    /**
     * Empty maps are left out: PHP encodes an empty array as a JSON list ([]) but a filled
     * one as an object, so keeping them would give one key two JSON types.
     *
     * @return array{exif?: non-empty-array<string, array<array-key, mixed>>, iptc?: non-empty-array<string, list<mixed>>}
     */
    public function toArray(): array
    {
        return array_filter(['exif' => $this->exif, 'iptc' => $this->iptc], static fn (array $map): bool => $map !== []);
    }
}
