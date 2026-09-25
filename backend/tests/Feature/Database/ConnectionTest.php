<?php

declare(strict_types=1);

use App\Models\Image;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// A failed INSERT is reported with its message; unmasked, that puts the uploader's e-mail
// (and megabytes of metadata) into the log.
it('keeps query bindings out of exception messages', function (): void {
    expect(fn (): Image => Image::factory()->create(['uploader_email' => 'jan.kowalski@example.com', 'size_bytes' => null]))
        ->toThrow(function (QueryException $exception): void {
            expect($exception->getMessage())->not->toContain('jan.kowalski@example.com')
                ->and($exception->getBindings())->toContain('jan.kowalski@example.com');
        });
});

it('runs the MariaDB session in UTC, like the app', function (): void {
    expect(DB::selectOne('SELECT @@session.time_zone AS tz')->tz)->toBe('+00:00');
})->skip(fn (): bool => DB::getDriverName() !== 'mariadb', 'MariaDB only');

// The guard in StoreImage only helps while the limit leaves room for the rest of the INSERT.
it('keeps the metadata limit well below the largest packet MariaDB accepts', function (): void {
    $maxPacket = (int) DB::selectOne('SELECT @@max_allowed_packet AS bytes')->bytes;

    expect(config()->integer('images.max_metadata_bytes'))->toBeLessThanOrEqual(intdiv($maxPacket, 2));
})->skip(fn (): bool => DB::getDriverName() !== 'mariadb', 'MariaDB only');
