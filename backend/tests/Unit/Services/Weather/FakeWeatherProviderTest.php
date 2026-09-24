<?php

declare(strict_types=1);

use App\Services\Weather\FakeWeatherProvider;
use Illuminate\Support\Facades\Http;

it('returns a constant temperature for the UTC hour of the moment without network access', function (): void {
    Http::fake();

    $reading = new FakeWeatherProvider()->temperatureAt(new DateTimeImmutable('2026-07-15T12:34:56+02:00'));

    expect($reading->celsius)->toBe(FakeWeatherProvider::CELSIUS)
        ->and($reading->hour->format(DATE_ATOM))->toBe('2026-07-15T10:00:00+00:00');

    Http::assertNothingSent();
});
