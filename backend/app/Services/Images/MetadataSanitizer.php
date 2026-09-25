<?php

declare(strict_types=1);

namespace App\Services\Images;

/**
 * Makes raw metadata JSON-encodable without losing information.
 *
 * EXIF and IPTC values are byte strings in whatever charset the writing device chose, or
 * plain binary (MakerNote, UserComment). Text is kept as it is; anything else is wrapped as
 * {"base64": "..."} rather than transcoded or replaced, so the original bytes survive and a
 * wrapped value can't be mistaken for text.
 *
 * Text means valid UTF-8 without C0 control characters other than tab, LF and CR. The others
 * mark binary data, and json_encode() writes most of them as a 6-byte "\u0001": a few MB of
 * them in a TIFF tag grew past MariaDB's max_allowed_packet. Base64 costs 4/3.
 */
final readonly class MetadataSanitizer
{
    private const string BINARY_CONTROL_CHARACTERS = '/[\x00-\x08\x0B\x0C\x0E-\x1F]/';

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
            is_string($value) => $this->isText($value) ? $value : ['base64' => base64_encode($value)],
            // JSON has no NaN/Infinity; PHP's own spelling keeps the value recognisable.
            is_float($value) && ! is_finite($value) => (string) $value,
            is_int($value), is_float($value), is_bool($value) => $value,
            // ext-exif and iptcparse() return nothing else; null keeps the output JSON-safe regardless.
            default => null,
        };
    }

    private function isText(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8') && preg_match(self::BINARY_CONTROL_CHARACTERS, $value) === 0;
    }
}
