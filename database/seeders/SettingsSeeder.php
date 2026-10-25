<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Models\DsaRate;
use App\Services\AppSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Configurable values the Chief of Staff edits in Settings:
 *
 * - Thresholds and tolerances (Settings › Thresholds): every setting gets a
 *   row with the FRD's proposed default. Existing values are never changed.
 * - DSA rates (Settings › DSA rates): the approved standard rate for each
 *   scale (job grade 1 to 10, as in the HR register) and INTERN (DsaRate::STANDARD, one rate whatever the destination),
 *   from DsaRate::STANDARD_FROM. Synced on every run: a missing rate is added
 *   and a changed amount is put back, so an installation always has the
 *   approved rates. Rates added later in Settings (a new effective date) and
 *   the retired destination rates are left alone; cost lines are never touched.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AppSettings::DEFINITIONS as $key => [, $default]) {
            AppSetting::query()->firstOrCreate(['key' => $key], ['value' => (string) $default]);
        }
        Cache::forget('app_settings');

        foreach (DsaRate::STANDARD as $grade => $amount) {
            $grade = (string) $grade; // PHP turns the scale keys into integers.
            $rate = DsaRate::query()->standard()->where('job_grade', $grade)->whereDate('effective_from', DsaRate::STANDARD_FROM)->first();

            if ($rate) {
                $rate->update(['amount' => $amount, 'is_provisional' => false]);
            } else {
                DsaRate::query()->create(['job_grade' => $grade, 'amount' => $amount, 'effective_from' => DsaRate::STANDARD_FROM]);
            }
        }
    }
}
