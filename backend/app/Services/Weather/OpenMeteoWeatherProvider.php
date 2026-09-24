<?php

declare(strict_types=1);

namespace App\Services\Weather;

use App\Contracts\WeatherProvider;
use App\Data\TemperatureReading;
use App\Exceptions\Weather\TemperatureNotAvailable;
use App\Exceptions\Weather\WeatherRequestFailed;
use App\Exceptions\Weather\WeatherRequestRejected;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Hourly forecast for today from Open-Meteo (https://open-meteo.com/en/docs).
 *
 * The response labels hours as local wall-clock times ("2026-07-15T12:00") shifted by a single
 * utc_offset_seconds for the whole answer - also on DST change days, where it still returns 24
 * labels (verified against the API). The requested hour is therefore located with that offset
 * rather than by converting the moment to Europe/Warsaw, which would pick the wrong value on
 * those days.
 */
final readonly class OpenMeteoWeatherProvider implements WeatherProvider
{
    private const string UNIT = '°C';

    public function __construct(
        private string $url,
        private float $latitude,
        private float $longitude,
        private string $timezone,
        private int $timeoutSeconds,
        private int $connectTimeoutSeconds,
        private int $attempts,
        private int $retrySleepMilliseconds,
    ) {}

    public function temperatureAt(DateTimeImmutable $moment): TemperatureReading
    {
        [$utcOffsetSeconds, $temperatures] = $this->parse($this->fetch());

        $label = TemperatureReading::hourOf($moment)->modify(sprintf('%+d seconds', $utcOffsetSeconds))->format('Y-m-d\TH:i');
        $celsius = $temperatures[$label] ?? null;

        if ($celsius === null) {
            throw TemperatureNotAvailable::forHour($label);
        }

        return new TemperatureReading($celsius, $moment);
    }

    private function fetch(): Response
    {
        try {
            $response = Http::acceptJson()
                ->timeout($this->timeoutSeconds)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->retry($this->attempts, $this->retrySleepMilliseconds, $this->isTransient(...), throw: false)
                ->get($this->url, [
                    'latitude' => $this->latitude,
                    'longitude' => $this->longitude,
                    'hourly' => 'temperature_2m',
                    'timezone' => $this->timezone,
                    'forecast_days' => 1,
                ]);
        } catch (ConnectionException $exception) {
            throw WeatherRequestFailed::because($exception);
        }

        if ($response->successful()) {
            return $response;
        }

        // Open-Meteo explains errors as {"error": true, "reason": "..."}.
        $reason = $response->json('reason');
        $reason = is_string($reason) ? $reason : null;

        throw $this->isTransientStatus($response->status())
            ? WeatherRequestFailed::httpStatus($response->status(), $reason)
            : WeatherRequestRejected::httpStatus($response->status(), $reason);
    }

    private function isTransient(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException && $this->isTransientStatus($exception->response->status()));
    }

    /**
     * Other client errors mean the request itself is wrong; repeating it can't help.
     */
    private function isTransientStatus(int $status): bool
    {
        return $status >= 500 || $status === 429;
    }

    /**
     * @return array{int, array<string, float|null>} UTC offset of the labels, and label => °C
     */
    private function parse(Response $response): array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw WeatherRequestFailed::malformedPayload('not a JSON object');
        }

        $utcOffsetSeconds = $payload['utc_offset_seconds'] ?? null;

        if (! is_int($utcOffsetSeconds)) {
            throw WeatherRequestFailed::malformedPayload('utc_offset_seconds is not an integer');
        }

        $unit = data_get($payload, 'hourly_units.temperature_2m');

        if ($unit !== self::UNIT) {
            throw WeatherRequestFailed::malformedPayload('temperature unit is not '.self::UNIT);
        }

        $times = data_get($payload, 'hourly.time');
        $temperatures = data_get($payload, 'hourly.temperature_2m');

        if (! is_array($times) || ! array_is_list($times) || ! is_array($temperatures) || ! array_is_list($temperatures)) {
            throw WeatherRequestFailed::malformedPayload('hourly time or temperature_2m is not a list');
        }

        if (count($times) !== count($temperatures)) {
            throw WeatherRequestFailed::malformedPayload('hourly time and temperature_2m differ in length');
        }

        $byLabel = [];

        foreach ($times as $index => $time) {
            $celsius = $temperatures[$index];

            if (! is_string($time) || ! ($celsius === null || is_int($celsius) || is_float($celsius))) {
                throw WeatherRequestFailed::malformedPayload("unexpected hourly value at index {$index}");
            }

            $byLabel[$time] = $celsius === null ? null : (float) $celsius;
        }

        return [$utcOffsetSeconds, $byLabel];
    }
}
