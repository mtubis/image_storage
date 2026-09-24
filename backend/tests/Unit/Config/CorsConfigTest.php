<?php

declare(strict_types=1);

/**
 * Evaluates config/cors.php as if FRONTEND_URL had the given value.
 *
 * @return array<string, mixed>
 */
function cors_config_with_frontend_url(string $url): array
{
    // Laravel's env() reads $_SERVER first (see phpunit.xml), on every call. An unset
    // variable can't be simulated here (env() falls back to the container's getenv());
    // it takes the same path as an empty one via env()'s '' default.
    $previous = $_SERVER['FRONTEND_URL'] ?? null;
    $_SERVER['FRONTEND_URL'] = $url;

    try {
        return require config_path('cors.php');
    } finally {
        if ($previous === null) {
            unset($_SERVER['FRONTEND_URL']);
        } else {
            $_SERVER['FRONTEND_URL'] = $previous;
        }
    }
}

it('allows exactly the FRONTEND_URL origin, without a trailing slash', function (string $url): void {
    expect(cors_config_with_frontend_url($url)['allowed_origins'])->toBe(['http://app.test:5173']);
})->with(['http://app.test:5173', 'http://app.test:5173/']);

it('allows no origin rather than any when FRONTEND_URL is empty', function (): void {
    expect(cors_config_with_frontend_url('')['allowed_origins'])->toBe([]);
});
