<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * No auth, so no default users to seed. The E2E data is seeded by
     * E2eImageSeeder, explicitly and only into the E2E stack (`make e2e`).
     */
    public function run(): void
    {
        //
    }
}
