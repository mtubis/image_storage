<?php

declare(strict_types=1);

namespace App\Services\Images;

/**
 * Makes raw metadata JSON-encodable without losing information.
 *
 * EXIF and IPTC values are byte strings in whatever charset the writing device chose, or
 * plain binary (MakerNote, UserComment). Valid UTF-8 is kept as it is; anything else is
 * wrapped as {"base64": "..."} rather than transcoded or replaced, so the original bytes
 * survive and a wrapped value can't be mistaken for text.
 */
final readonly class MetadataSanitizer
{
    /**
     * Keys are not checked: ext-exif and iptcparse() generate them (tag names, "2#025"),
     * they never come from the file's bytes.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function sanitize(array $values): array
    {
        return array_map($this->sanitizeValue(...), $values);
    }

    /**
     * @return array<array-key, mixed>|string|int|float|bool|null
     */
    private function sanitizeValue(mixed $value): array|string|int|float|bool|null
    {
        return match (true) {
            is_array($value) => $this->sanitize($value),
            is_string($value) => mb_check_encoding($value, 'UTF-8') ? $value : ['base64' => base64_encode($value)],
            // JSON has no NaN/Infinity; PHP's own spelling keeps the value recognisable.
            is_float($value) && ! is_finite($value) => (string) $value,
            is_int($value), is_float($value), is_bool($value) => $value,
            // ext-exif and iptcparse() return nothing else; null keeps the output JSON-safe regardless.
            default => null,
        };
    }
}
