<?php

declare(strict_types=1);

// The production connection. Tests run on SQLite by default; see the MariaDB test target.
it('pins the MariaDB session to UTC and masks bindings in exception messages', function (): void {
    expect(config('database.connections.mariadb'))->toMatchArray([
        'driver' => 'mariadb',
        // TIMESTAMP columns are converted from and to the session time zone. The server's
        // default (SYSTEM) would shift created_at, or reject times in a DST gap.
        'timezone' => '+00:00',
        'mask_bindings_in_exception_messages' => true,
    ]);
});
