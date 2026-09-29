<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Everything a new installation needs. Safe to re-run at any time.
 *
 * System data, kept in step with the code on every run (not editable in the app):
 *   RolesAndPermissionsSeeder  the three roles and the FRD 4.1 permission matrix
 *   GeographySeeder            10 regions, 47 counties, 290 constituencies
 *
 * Configuration the Chief of Staff edits in Settings, seeded once and then left alone:
 *   ReferenceDataSeeder        departments, designations, duty stations, activity
 *                              categories and types, cost types, county DSA destinations
 *   SettingsSeeder             thresholds and tolerances; provisional DSA rates
 *
 * Local machine only:
 *   UserSeeder                 the three accounts, password "password"
 *
 * Staging and production accounts: php artisan activities:create-user.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            GeographySeeder::class,
            ReferenceDataSeeder::class,
            SettingsSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call(UserSeeder::class);
        }
    }
}
