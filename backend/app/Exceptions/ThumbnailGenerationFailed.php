<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class ThumbnailGenerationFailed extends RuntimeException
{
    public static function unsupportedType(string $mimeType): self
    {
        return new self("Cannot generate a thumbnail for content of type [{$mimeType}].");
    }

    public static function because(Throwable $previous): self
    {
        return new self('Cannot generate a thumbnail: '.$previous->getMessage(), previous: $previous);
    }
}
