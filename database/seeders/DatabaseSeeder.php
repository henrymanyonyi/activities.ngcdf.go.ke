<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Roles, geography and starting reference lists only. The three user
     * accounts are created with `php artisan activities:create-user`, never
     * seeded with known passwords. Safe to re-run.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            GeographySeeder::class,
            ReferenceDataSeeder::class,
        ]);
    }
}
