<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\TemperatureReading;
use App\Exceptions\Weather\TemperatureNotAvailable;
use App\Exceptions\Weather\WeatherRequestFailed;
use App\Exceptions\Weather\WeatherRequestRejected;
use DateTimeImmutable;

interface WeatherProvider
{
    /**
     * Air temperature at the configured location for the hour containing the given moment.
     *
     * The moment is an instant (any timezone); the provider maps it to the hour its data uses,
     * so a delayed call still returns the temperature of that moment, not of "now".
     *
     * @throws WeatherRequestFailed when the provider cannot be reached, is unavailable or
     *                              rate-limited, or answers with an unexpected payload;
     *                              retrying may help
     * @throws WeatherRequestRejected when the provider refuses the request itself; retrying
     *                                will not help
     * @throws TemperatureNotAvailable when the answer has no value for that hour; retrying
     *                                 will not help
     */
    public function temperatureAt(DateTimeImmutable $moment): TemperatureReading;
}
