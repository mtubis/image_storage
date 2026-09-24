<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * The file name a download is saved under: the original UTF-8 name for clients that read
 * "filename*" (RFC 6266), plus a printable ASCII "filename" fallback for those that don't.
 *
 * Laravel's own fallback (Str::ascii() minus "%") turns an untransliterable name like
 * "日本語.jpg" into ".jpg", a nameless hidden file, and path separators into a 500.
 */
final readonly class DownloadFilename
{
    private function __construct(
        public string $name,
        public string $fallback,
    ) {}

    /**
     * @param  string  $extension  the stored (detected) extension of the file
     * @param  list<string>  $typeExtensions  every extension of the detected type (e.g. jpg, jpeg)
     */
    public static function from(string $originalName, string $extension, array $typeExtensions): self
    {
        // Symfony rejects path separators in either name; a download name is never a path.
        $name = str_replace(['/', '\\'], '_', $originalName);

        // The client name is not validated, so "photo.jpg" may hold WebP bytes. Appending rather
        // than replacing keeps what the uploader typed ("photo.jpg.webp") and opens correctly.
        $suffix = Str::lower(pathinfo($name, PATHINFO_EXTENSION));

        if (! in_array($suffix, $typeExtensions, true)) {
            $name .= ".{$extension}";
        }

        // Printable ASCII only; "%" could be read as a percent-escape by the client.
        $fallback = (string) preg_replace('/[^\x20-\x7e]|%/', '', Str::ascii($name));
        // Dropped characters may leave "Foto .jpg"; no whitespace around the stem.
        $fallback = trim((string) preg_replace('/\s+(?=\.[^.]*$)/', '', $fallback));

        if (pathinfo($fallback, PATHINFO_FILENAME) === '') {
            $fallback = "image.{$extension}";
        }

        return new self($name, $fallback);
    }

    public function contentDisposition(): string
    {
        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $this->name, $this->fallback);
    }
}
