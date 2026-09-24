<?php

declare(strict_types=1);

namespace App\Services\Weather;

use App\Contracts\WeatherProvider;
use App\Data\TemperatureReading;
use DateTimeImmutable;

/**
 * Deterministic, offline stand-in selected with WEATHER_PROVIDER=fake (E2E runs, offline work).
 */
final readonly class FakeWeatherProvider implements WeatherProvider
{
    public const float CELSIUS = 21.5;

    public function temperatureAt(DateTimeImmutable $moment): TemperatureReading
    {
        return new TemperatureReading(self::CELSIUS, $moment);
    }
}
