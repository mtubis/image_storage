<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Exceptions\ThumbnailGenerationFailed;

interface ThumbnailGenerator
{
    /**
     * Render a browser-displayable WebP thumbnail of an uploaded original.
     *
     * Works on bytes, not paths, so the caller decides where originals and thumbnails are
     * stored. The thumbnail is displayed upright (EXIF orientation applied), shows only the
     * first page or frame and carries no metadata.
     *
     * @return string WebP bytes
     *
     * @throws ThumbnailGenerationFailed when the contents are not a decodable image of an
     *                                   allowed type or exceed the resource limits
     */
    public function generate(string $contents): string;
}
