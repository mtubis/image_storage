<?php

declare(strict_types=1);

// docker-compose sets APP_ENV, DB_* and QUEUE_CONNECTION on the container. phpunit.xml uses
// <server> so the testing values override $_SERVER, which Laravel reads first; otherwise
// RefreshDatabase would wipe the dev database.
it('runs against an isolated in-memory sqlite database', function (): void {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
});

it('overrides the container environment with the testing one', function (): void {
    expect(app()->environment())->toBe('testing')
        ->and(config('queue.default'))->toBe('sync');
});
