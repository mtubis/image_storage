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
     * No auth (see CLAUDE.md), so no default users to seed here; a
     * dedicated seeder for E2E list scenarios is added in step 4.1.
     */
    public function run(): void
    {
        //
    }
}
