<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\WeatherProvider;
use App\Data\TemperatureReading;
use DateTimeImmutable;
use Throwable;

/**
 * A WeatherProvider stand-in that records every moment it is asked about and answers with
 * the given temperature, or throws the given exception.
 */
final class StubWeatherProvider implements WeatherProvider
{
    /** @var list<DateTimeImmutable> */
    public array $moments = [];

    public function __construct(private readonly float|Throwable $answer) {}

    public function temperatureAt(DateTimeImmutable $moment): TemperatureReading
    {
        $this->moments[] = $moment;

        if ($this->answer instanceof Throwable) {
            throw $this->answer;
        }

        return new TemperatureReading($this->answer, $moment);
    }
}
