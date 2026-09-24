<?php

declare(strict_types=1);

use App\Contracts\WeatherProvider;
use App\Data\TemperatureReading;
use App\Exceptions\Weather\TemperatureNotAvailable;
use App\Exceptions\Weather\WeatherRequestFailed;
use App\Exceptions\Weather\WeatherRequestRejected;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * An Open-Meteo answer shaped like the real one: 24 hourly labels of one local day, all
 * shifted by the single utc_offset_seconds of the response (also on DST change days).
 *
 * @param  array<int, float|int|null>  $overrides  hour of day => temperature
 * @return array<string, mixed>
 */
function open_meteo_payload(string $date, int $utcOffsetSeconds, array $overrides = []): array
{
    $times = [];
    $temperatures = [];

    foreach (range(0, 23) as $hour) {
        $times[] = sprintf('%sT%02d:00', $date, $hour);
        $temperatures[] = array_key_exists($hour, $overrides) ? $overrides[$hour] : 10.0 + $hour / 10;
    }

    return [
        'latitude' => 50.26566,
        'longitude' => 19.02475,
        'generationtime_ms' => 0.04,
        'utc_offset_seconds' => $utcOffsetSeconds,
        'timezone' => 'Europe/Warsaw',
        'timezone_abbreviation' => $utcOffsetSeconds === 7200 ? 'GMT+2' : 'GMT+1',
        'elevation' => 270.0,
        'hourly_units' => ['time' => 'iso8601', 'temperature_2m' => '°C'],
        'hourly' => ['time' => $times, 'temperature_2m' => $temperatures],
    ];
}

/**
 * @param  array<string, mixed>|string  $body
 */
function fake_open_meteo(array|string $body, int $status = 200): void
{
    Http::fake(['api.open-meteo.com/*' => Http::response($body, $status)]);
}

function temperature_at(string $moment): TemperatureReading
{
    return resolve(WeatherProvider::class)->temperatureAt(new DateTimeImmutable($moment));
}

beforeEach(function (): void {
    config(['services.weather.provider' => 'open-meteo']);
    Sleep::fake();
});

it('requests the hourly Katowice forecast given by the assignment', function (): void {
    fake_open_meteo(open_meteo_payload('2026-07-15', 7200));

    temperature_at('2026-07-15T10:30:00Z');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.open-meteo.com/v1/forecast?latitude=50.25841&longitude=19.02754'
            .'&hourly=temperature_2m&timezone=Europe%2FWarsaw&forecast_days=1');
});

it('applies the configured timeouts', function (): void {
    config(['services.open_meteo.timeout' => 7, 'services.open_meteo.connect_timeout' => 2]);
    $options = [];

    Http::fake(function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response(open_meteo_payload('2026-07-15', 7200));
    });

    temperature_at('2026-07-15T10:30:00Z');

    expect($options)->toMatchArray(['timeout' => 7, 'connect_timeout' => 2]);
});

describe('hour selection', function (): void {
    it('picks the local hour of the moment in summer (UTC+2)', function (): void {
        fake_open_meteo(open_meteo_payload('2026-07-15', 7200, [11 => 20.0, 12 => 21.5, 13 => 23.0]));

        $reading = temperature_at('2026-07-15T10:30:00Z');

        expect($reading->celsius)->toBe(21.5)
            ->and($reading->hour->format(DATE_ATOM))->toBe('2026-07-15T10:00:00+00:00');
    });

    it('picks the local hour of the moment in winter (UTC+1)', function (): void {
        fake_open_meteo(open_meteo_payload('2026-01-15', 3600, [10 => -3.0, 11 => -2.5, 12 => -2.0]));

        expect(temperature_at('2026-01-15T10:30:00Z')->celsius)->toBe(-2.5);
    });

    it('picks the local hour across the UTC date boundary', function (): void {
        // 23:15 UTC on the 14th is 01:15 on the 15th in Warsaw.
        fake_open_meteo(open_meteo_payload('2026-07-15', 7200, [1 => 14.2]));

        expect(temperature_at('2026-07-14T23:15:00Z')->celsius)->toBe(14.2);
    });

    // On DST change days Open-Meteo still returns 24 labels shifted by one utc_offset_seconds
    // for the whole answer (observed: 7200 for both 2025-10-26 and 2026-03-29 when queried in
    // September 2026), so the label must come from that offset, whatever it is - not from the
    // Warsaw offset of the moment.
    it('follows the single offset of the response on the spring DST change day', function (int $utcOffsetSeconds, int $labelHour): void {
        // 10:30 UTC is 12:30 in Warsaw (UTC+2 since 01:00 UTC).
        fake_open_meteo(open_meteo_payload('2026-03-29', $utcOffsetSeconds, [$labelHour => 6.5]));

        $reading = temperature_at('2026-03-29T10:30:00Z');

        expect($reading->celsius)->toBe(6.5)
            ->and($reading->hour->format(DATE_ATOM))->toBe('2026-03-29T10:00:00+00:00');
    })->with([
        'offset UTC+1' => [3600, 11],
        'offset UTC+2' => [7200, 12],
    ]);

    it('keeps the repeated local hour apart on the autumn DST change day', function (): void {
        // 00:30 and 01:30 UTC on 25 October 2026 are both 02:30 in Warsaw.
        fake_open_meteo(open_meteo_payload('2026-10-25', 7200, [2 => 9.0, 3 => 8.0]));

        expect(temperature_at('2026-10-25T00:30:00Z')->celsius)->toBe(9.0)
            ->and(temperature_at('2026-10-25T01:30:00Z')->celsius)->toBe(8.0);
    });

    it('has no value for the 25th Warsaw hour of the autumn DST change day', function (): void {
        // 22:30 UTC is 23:30 in Warsaw, but the 24 labels with offset UTC+2 end at 21:00 UTC.
        fake_open_meteo(open_meteo_payload('2026-10-25', 7200));

        temperature_at('2026-10-25T22:30:00Z');
    })->throws(TemperatureNotAvailable::class, '2026-10-26T00:00');

    it('treats the moment as an instant regardless of its timezone', function (string $moment): void {
        fake_open_meteo(open_meteo_payload('2026-07-15', 7200, [12 => 21.5]));

        expect(temperature_at($moment)->celsius)->toBe(21.5);
    })->with([
        'UTC' => '2026-07-15T10:30:00Z',
        'Warsaw' => '2026-07-15T12:30:00+02:00',
        'New York' => '2026-07-15T06:30:00-04:00',
    ]);

    it('uses the hour that contains the moment', function (string $moment): void {
        fake_open_meteo(open_meteo_payload('2026-07-15', 7200, [11 => 20.0, 12 => 21.5, 13 => 23.0]));

        expect(temperature_at($moment)->celsius)->toBe(21.5);
    })->with([
        'start of the hour' => '2026-07-15T10:00:00Z',
        'last second of the hour' => '2026-07-15T10:59:59.999999Z',
    ]);

    it('returns integer temperatures as floats', function (): void {
        fake_open_meteo(open_meteo_payload('2026-07-15', 7200, [12 => 21]));

        expect(temperature_at('2026-07-15T10:30:00Z')->celsius)->toBe(21.0);
    });
});

describe('missing temperature', function (): void {
    it('fails permanently when the hour is outside the returned day', function (): void {
        fake_open_meteo(open_meteo_payload('2026-07-16', 7200));

        temperature_at('2026-07-15T10:30:00Z');
    })->throws(TemperatureNotAvailable::class, '2026-07-15T12:00');

    it('fails permanently when the value for the hour is null', function (): void {
        fake_open_meteo(open_meteo_payload('2026-07-15', 7200, [12 => null]));

        temperature_at('2026-07-15T10:30:00Z');
    })->throws(TemperatureNotAvailable::class);
});

describe('failed requests', function (): void {
    it('fails when the connection times out, after retrying', function (): void {
        Http::fake(['api.open-meteo.com/*' => Http::failedConnection()]);

        expect(fn (): TemperatureReading => temperature_at('2026-07-15T10:30:00Z'))
            ->toThrow(WeatherRequestFailed::class);

        Http::assertSentCount(2);
    });

    it('fails as retryable on a server error or rate limiting, after retrying', function (int $status): void {
        fake_open_meteo(['error' => true, 'reason' => 'Try again later'], $status);

        expect(fn (): TemperatureReading => temperature_at('2026-07-15T10:30:00Z'))
            ->toThrow(WeatherRequestFailed::class, "HTTP status {$status}: Try again later.");

        Http::assertSentCount(2);
    })->with([
        'internal server error' => 500,
        'service unavailable' => 503,
        'too many requests' => 429,
    ]);

    it('fails as rejected on a client error, without retrying', function (int $status): void {
        fake_open_meteo(['error' => true, 'reason' => 'Cannot initialize WeatherVariable'], $status);

        expect(fn (): TemperatureReading => temperature_at('2026-07-15T10:30:00Z'))
            ->toThrow(WeatherRequestRejected::class, "HTTP status {$status}: Cannot initialize WeatherVariable.");

        Http::assertSentCount(1);
    })->with([
        'bad request' => 400,
        'not found' => 404,
    ]);

    it('reports the status alone when the error body has no reason', function (): void {
        fake_open_meteo('<html>Bad Gateway</html>', 502);

        temperature_at('2026-07-15T10:30:00Z');
    })->throws(WeatherRequestFailed::class, 'Weather request failed with HTTP status 502.');

    it('succeeds when a retry succeeds', function (): void {
        Http::fakeSequence('api.open-meteo.com/*')
            ->push('Service Unavailable', 503)
            ->push(open_meteo_payload('2026-07-15', 7200, [12 => 21.5]));

        expect(temperature_at('2026-07-15T10:30:00Z')->celsius)->toBe(21.5);

        Http::assertSentCount(2);
    });

    it('waits between retries', function (): void {
        fake_open_meteo('Service Unavailable', 503);

        rescue(fn (): TemperatureReading => temperature_at('2026-07-15T10:30:00Z'), report: false);

        Sleep::assertSleptTimes(1);
    });
});

it('fails on a malformed payload', function (array|string $body): void {
    fake_open_meteo($body);

    temperature_at('2026-07-15T10:30:00Z');
})->throws(WeatherRequestFailed::class, 'malformed')->with([
    'not JSON' => ['<html>Gateway</html>'],
    'JSON scalar' => ['"ok"'],
    'hourly missing' => [array_diff_key(open_meteo_payload('2026-07-15', 7200), ['hourly' => true])],
    'utc offset missing' => [array_diff_key(open_meteo_payload('2026-07-15', 7200), ['utc_offset_seconds' => true])],
    'utc offset not an integer' => [['utc_offset_seconds' => '7200'] + open_meteo_payload('2026-07-15', 7200)],
    'units missing' => [array_diff_key(open_meteo_payload('2026-07-15', 7200), ['hourly_units' => true])],
    'unexpected unit' => [['hourly_units' => ['time' => 'iso8601', 'temperature_2m' => '°F']] + open_meteo_payload('2026-07-15', 7200)],
    'times not a list' => [['hourly' => ['time' => ['a' => '2026-07-15T12:00'], 'temperature_2m' => [21.5]]] + open_meteo_payload('2026-07-15', 7200)],
    'time not a string' => [['hourly' => ['time' => [1_784_109_600], 'temperature_2m' => [21.5]]] + open_meteo_payload('2026-07-15', 7200)],
    'temperatures missing' => [['hourly' => ['time' => ['2026-07-15T12:00']]] + open_meteo_payload('2026-07-15', 7200)],
    'temperature not numeric' => [['hourly' => ['time' => ['2026-07-15T12:00'], 'temperature_2m' => ['21.5']]] + open_meteo_payload('2026-07-15', 7200)],
    'arrays of different lengths' => [['hourly' => ['time' => ['2026-07-15T11:00', '2026-07-15T12:00'], 'temperature_2m' => [21.5]]] + open_meteo_payload('2026-07-15', 7200)],
]);
