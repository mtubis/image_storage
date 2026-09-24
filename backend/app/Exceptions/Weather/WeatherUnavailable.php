<?php

declare(strict_types=1);

namespace App\Exceptions\Weather;

use RuntimeException;

/**
 * Common parent, so a caller that doesn't care why can catch every weather failure at once.
 */
abstract class WeatherUnavailable extends RuntimeException {}
