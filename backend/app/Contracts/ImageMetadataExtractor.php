<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\ImageMetadata;

interface ImageMetadataExtractor
{
    /**
     * Read the EXIF and IPTC metadata embedded in an image, as raw as the platform exposes it.
     *
     * Works on bytes, like ThumbnailGenerator. Metadata is optional and often damaged, so
     * missing, unreadable or unsupported metadata yields an empty or partial result, never
     * an exception.
     */
    public function extract(string $contents): ImageMetadata;
}
