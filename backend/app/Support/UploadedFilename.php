<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The client's file name, kept for display and the download only. It is not validated (a
 * strange name is no reason to refuse a valid image), so it is made storable here: valid
 * UTF-8, no control or bidi characters, at most the column's length.
 */
final class UploadedFilename
{
    /**
     * A regex character class (without brackets): control characters, plus the bidirectional
     * controls (ALM, LRM/RLM, embeddings, overrides, isolates) that make "photo\u{202E}gpj.exe"
     * display as "photoexe.jpg". Other format characters, such as ZWNJ, occur in real names.
     */
    public const string UNSAFE_CHARACTERS = '\p{Cc}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}';

    // Length of the images.original_name column, in characters.
    private const int MAX_LENGTH = 255;

    // A "suffix" longer than this is part of the name, not an extension worth keeping.
    private const int MAX_KEPT_SUFFIX_LENGTH = 16;

    /**
     * @param  string  $clientName  as sent by the client, without any path (Symfony's getClientOriginalName())
     * @param  string  $extension  the detected extension, for the fallback name
     */
    public static function normalize(string $clientName, string $extension): string
    {
        $name = mb_scrub($clientName, 'UTF-8');
        $name = trim((string) preg_replace('/['.self::UNSAFE_CHARACTERS.']/u', '', $name));

        if ($name === '') {
            return "image.{$extension}";
        }

        if (mb_strlen($name) <= self::MAX_LENGTH) {
            return $name;
        }

        // Shorten the stem, not the extension: "….jpg" still says what the file is.
        $dot = mb_strrpos($name, '.');
        $suffix = $dot === false ? '' : mb_substr($name, $dot);

        if (mb_strlen($suffix) > self::MAX_KEPT_SUFFIX_LENGTH) {
            $suffix = '';
        }

        return mb_substr($name, 0, self::MAX_LENGTH - mb_strlen($suffix)).$suffix;
    }
}
