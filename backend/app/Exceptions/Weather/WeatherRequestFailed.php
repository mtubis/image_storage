<?php

declare(strict_types=1);

namespace App\Exceptions\Weather;

use Throwable;

/**
 * The provider could not be reached, was unavailable (5xx), rate-limited the request (429) or
 * answered with an unexpected payload. Transient by nature, so worth retrying.
 */
final class WeatherRequestFailed extends WeatherUnavailable
{
    public static function because(Throwable $previous): self
    {
        return new self('Weather request failed: '.$previous->getMessage(), previous: $previous);
    }

    public static function httpStatus(int $status, ?string $reason): self
    {
        return new self(rtrim("Weather request failed with HTTP status {$status}: ".($reason ?? ''), ': ').'.');
    }

    public static function malformedPayload(string $reason): self
    {
        return new self("Weather response is malformed: {$reason}.");
    }
}
