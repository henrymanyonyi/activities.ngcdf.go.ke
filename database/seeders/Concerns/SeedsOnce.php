<?php

namespace Database\Seeders\Concerns;

use App\Models\AppSetting;
use Closure;

/**
 * Seeds an editable list once per installation. Afterwards the list belongs
 * to the Chief of Staff: re-running the seeder never renames, re-adds or
 * re-activates anything that was changed in Settings. A marker row in
 * app_settings records that the step ran.
 */
trait SeedsOnce
{
    protected function once(string $step, Closure $seed): void
    {
        $key = 'seeded.'.$step;

        if (AppSetting::query()->whereKey($key)->exists()) {
            $this->command?->line("  <fg=gray>{$step}: already seeded, left as edited</>");

            return;
        }

        $seed();
        AppSetting::query()->create(['key' => $key, 'value' => now()->toDateTimeString()]);
        $this->command?->line("  <fg=green>{$step}: seeded</>");
    }
}
