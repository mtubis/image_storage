<?php

declare(strict_types=1);

use App\Contracts\WeatherProvider;
use App\Services\Weather\FakeWeatherProvider;
use App\Services\Weather\OpenMeteoWeatherProvider;

it('binds the weather provider selected by configuration', function (string $provider, string $class): void {
    config(['services.weather.provider' => $provider]);

    expect(resolve(WeatherProvider::class))->toBeInstanceOf($class);
})->with([
    'Open-Meteo' => ['open-meteo', OpenMeteoWeatherProvider::class],
    'fake' => ['fake', FakeWeatherProvider::class],
]);

it('refuses an unknown weather provider instead of falling back to a real one', function (): void {
    config(['services.weather.provider' => 'open-meteo-typo']);

    resolve(WeatherProvider::class);
})->throws(InvalidArgumentException::class, 'open-meteo-typo');
