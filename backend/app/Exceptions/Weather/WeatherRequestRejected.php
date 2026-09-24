<?php

declare(strict_types=1);

namespace App\Exceptions\Weather;

/**
 * The provider refused the request itself (a 4xx other than rate limiting), e.g. because of
 * invalid parameters. Repeating the same request cannot succeed, so it is not worth retrying.
 */
final class WeatherRequestRejected extends WeatherUnavailable
{
    public static function httpStatus(int $status, ?string $reason): self
    {
        return new self(rtrim("Weather request rejected with HTTP status {$status}: ".($reason ?? ''), ': ').'.');
    }
}
