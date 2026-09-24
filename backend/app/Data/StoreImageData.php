<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A validated upload, detached from the HTTP layer.
 *
 * Type and extension are the ones detected from the content during validation, never the
 * client's claim; the original name is kept only for display and the download.
 */
final readonly class StoreImageData
{
    /**
     * @param  string  $contents  the uploaded file's bytes
     * @param  string  $originalName  client-supplied file name
     * @param  string  $mimeType  canonical detected MIME type, a key of config('images.allowed_types')
     * @param  string  $extension  extension for the detected type, without the dot
     */
    public function __construct(
        public string $contents,
        public string $originalName,
        public string $mimeType,
        public string $extension,
        public string $uploaderName,
        public string $uploaderEmail,
    ) {}
}
