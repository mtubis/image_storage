<?php

declare(strict_types=1);

use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Absolute path of a committed fixture from tests/Fixtures (see its README.md).
 */
function fixture_path(string $name): string
{
    $path = __DIR__.'/Fixtures/'.$name;

    // Fail loudly: a typo would otherwise surface as a confusing validation or decode error.
    if (! is_file($path)) {
        throw new RuntimeException("Unknown test fixture [{$name}].");
    }

    return $path;
}
