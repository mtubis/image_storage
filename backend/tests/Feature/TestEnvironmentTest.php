<?php

declare(strict_types=1);

// docker-compose sets APP_ENV, DB_* and QUEUE_CONNECTION on the container. phpunit.xml (and
// phpunit.mariadb.xml) use <server> so the testing values override $_SERVER, which Laravel
// reads first; otherwise RefreshDatabase would wipe the dev database.
it('runs against an isolated test database, never the dev one', function (): void {
    $connection = config()->string('database.default');

    expect([$connection, config("database.connections.{$connection}.database")])->toBeIn([
        ['sqlite', ':memory:'],
        // phpunit.mariadb.xml (make test-be-mariadb)
        ['mariadb', 'image_storage_testing'],
    ]);
});

it('keeps phpunit.mariadb.xml identical to phpunit.xml apart from the database', function (): void {
    $sqlite = <<<'XML'
                <server name="DB_CONNECTION" value="sqlite"/>
                <server name="DB_DATABASE" value=":memory:"/>
        XML;
    $mariadb = <<<'XML'
                <!-- `make test-be-mariadb`: the production database engine, in a database of its own.
                     Host and credentials come from the container (docker-compose.yml, CI). The rest of
                     this file must equal phpunit.xml (TestEnvironmentTest). -->
                <server name="DB_CONNECTION" value="mariadb"/>
                <server name="DB_DATABASE" value="image_storage_testing"/>
        XML;

    expect(file_get_contents(base_path('phpunit.xml')))->toContain($sqlite)
        ->and(file_get_contents(base_path('phpunit.mariadb.xml')))
        ->toBe(str_replace($sqlite, $mariadb, (string) file_get_contents(base_path('phpunit.xml'))));
});

it('overrides the container environment with the testing one', function (): void {
    expect(app()->environment())->toBe('testing')
        ->and(config('queue.default'))->toBe('sync');
});
