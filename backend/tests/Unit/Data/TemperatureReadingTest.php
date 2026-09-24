<?php

declare(strict_types=1);

use App\Data\TemperatureReading;

it('normalises the moment to the start of its hour in UTC', function (string $moment): void {
    $reading = new TemperatureReading(21.5, new DateTimeImmutable($moment));

    expect($reading->hour->format(DATE_ATOM))->toBe('2026-07-15T10:00:00+00:00')
        ->and($reading->hour->getTimezone()->getName())->toBe('UTC');
})->with([
    'UTC' => '2026-07-15T10:30:00Z',
    'Warsaw' => '2026-07-15T12:59:59.999999+02:00',
    'New York' => '2026-07-15T06:00:00-04:00',
]);
