<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class MetadataTooLarge extends RuntimeException
{
    public static function encodedSize(int $bytes, int $limit): self
    {
        return new self("Encoded metadata takes {$bytes} bytes, more than the limit of {$limit}.");
    }
}
