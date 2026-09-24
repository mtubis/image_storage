<?php

declare(strict_types=1);

namespace App\Data;

use DateTimeImmutable;
use DateTimeZone;

final readonly class TemperatureReading
{
    /**
     * Start of the hour the value applies to, in UTC.
     */
    public DateTimeImmutable $hour;

    /**
     * @param  DateTimeImmutable  $moment  any instant within that hour, in any timezone
     */
    public function __construct(
        public float $celsius,
        DateTimeImmutable $moment,
    ) {
        $this->hour = self::hourOf($moment);
    }

    /**
     * Start of the hour containing the moment, in UTC.
     */
    public static function hourOf(DateTimeImmutable $moment): DateTimeImmutable
    {
        $utc = $moment->setTimezone(new DateTimeZone('UTC'));

        return $utc->setTime((int) $utc->format('G'), 0);
    }
}
