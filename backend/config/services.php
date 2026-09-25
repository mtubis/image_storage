<?php

declare(strict_types=1);

return [
    // "fake" returns a constant temperature without network access (E2E, local work offline).
    'weather' => [
        'provider' => env('WEATHER_PROVIDER', 'open-meteo'),
    ],

    // Katowice, as given by the assignment. The request also fixes hourly=temperature_2m and
    // forecast_days=1, because the response parser depends on them.
    'open_meteo' => [
        'url' => 'https://api.open-meteo.com/v1/forecast',
        'latitude' => 50.25841,
        'longitude' => 19.02754,
        'timezone' => 'Europe/Warsaw',
        // Seconds; the job that calls the provider retries with backoff on top of this.
        'timeout' => 5,
        'connect_timeout' => 3,
        // Attempts in total, including the first one (Laravel's retry() semantics).
        'attempts' => 2,
        'retry_sleep_ms' => 250,
    ],

];
