<?php

declare(strict_types=1);

namespace App\Exceptions\Weather;

/**
 * A valid answer without a value for the requested hour, e.g. because the hour has left the
 * forecast window. Retrying will not bring it back.
 */
final class TemperatureNotAvailable extends WeatherUnavailable
{
    public static function forHour(string $hour): self
    {
        return new self("No temperature available for hour [{$hour}].");
    }
}
