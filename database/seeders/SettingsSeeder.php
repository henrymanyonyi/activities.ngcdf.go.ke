<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Models\DsaRate;
use App\Services\AppSettings;
use App\Support\FinancialYear;
use Database\Seeders\Concerns\SeedsOnce;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Configurable values the Chief of Staff edits in Settings:
 *
 * - Thresholds and tolerances (Settings › Thresholds): every setting gets a
 *   row with the FRD's proposed default. Existing values are never changed.
 * - DSA rates (Settings › DSA rates): provisional placeholder rates, only on
 *   an installation that has no rates at all, marked "Provisional" until the
 *   Finance circular replaces them (OI-01). Adding the real rate for a grade
 *   and destination ends the provisional one.
 */
class SettingsSeeder extends Seeder
{
    use SeedsOnce;

    /**
     * Placeholders, not official figures: KES per night.
     *
     * @var array<string, array{Cities: string, 'Other towns': string}>
     */
    public const PROVISIONAL_DSA = [
        'NGCDF 1' => ['Cities' => '18000.00', 'Other towns' => '15000.00'],
        'NGCDF 2' => ['Cities' => '16000.00', 'Other towns' => '13500.00'],
        'NGCDF 3' => ['Cities' => '14000.00', 'Other towns' => '12000.00'],
        'NGCDF 4' => ['Cities' => '12600.00', 'Other towns' => '10500.00'],
        'NGCDF 5' => ['Cities' => '10500.00', 'Other towns' => '8400.00'],
        'NGCDF 6' => ['Cities' => '8400.00', 'Other towns' => '7000.00'],
        'NGCDF 7' => ['Cities' => '7000.00', 'Other towns' => '5600.00'],
        'NGCDF 8' => ['Cities' => '7000.00', 'Other towns' => '5600.00'],
    ];

    public function run(): void
    {
        foreach (AppSettings::DEFINITIONS as $key => [, $default]) {
            AppSetting::query()->firstOrCreate(['key' => $key], ['value' => (string) $default]);
        }
        Cache::forget('app_settings');

        $this->once('dsa_rates', function () {
            if (DsaRate::query()->exists()) {
                $this->command?->line('  <fg=gray>dsa_rates: rates already entered, no placeholders added</>');

                return;
            }

            $from = FinancialYear::current()->start();
            foreach (self::PROVISIONAL_DSA as $grade => $rates) {
                foreach ($rates as $destination => $amount) {
                    DsaRate::query()->create([
                        'job_grade' => $grade,
                        'destination_category' => $destination,
                        'amount' => $amount,
                        'effective_from' => $from,
                        'is_provisional' => true,
                    ]);
                }
            }
        });
    }
}
