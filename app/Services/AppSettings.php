<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Thresholds and tolerances the FRD leaves configurable, with the FRD's
 * proposed defaults. Editable by the Chief of Staff in Settings.
 */
class AppSettings
{
    /** key => [label, default, help] */
    public const DEFINITIONS = [
        'late_notice_working_days' => ['Late-notice period (working days)', 7, 'Activities recorded fewer working days than this before the start date are flagged as late notice (OI-04).'],
        'report_due_working_days' => ['Report due period (working days)', 5, 'Back-to-office report is due this many working days after the end date (OI-07).'],
        'field_days_threshold_quarter' => ['Field days per officer per quarter', 30, 'Flag an officer whose cumulative field days would exceed this in a quarter (OI-05).'],
        'field_days_threshold_year' => ['Field days per officer per financial year', 100, 'Flag an officer whose cumulative field days would exceed this in a financial year (OI-05).'],
        'tolerance_date_shift_days' => ['Date change tolerance (days)', 2, 'After approval, moving the dates by more than this returns the activity to the CEO (BR-10).'],
        'tolerance_extension_days' => ['Extension tolerance (days)', 1, 'After approval, extending by more than this returns the activity to the CEO (EX-04).'],
        'tolerance_participant_changes' => ['Participant change tolerance (people)', 1, 'After approval, adding or removing more than this many people returns the activity to the CEO (PT-07).'],
        'tolerance_cost_increase_percent' => ['Cost increase tolerance (%)', 10, 'After approval, a planned-cost increase above this percentage returns the activity to the CEO (BR-10).'],
        'dsa_basis' => ['DSA basis', 'nights', 'Whether planned DSA is rate × nights or rate × days (OI-01): "nights" or "days".'],
    ];

    private const CACHE_KEY = 'app_settings';

    public function get(string $key): mixed
    {
        $values = Cache::rememberForever(self::CACHE_KEY, fn () => AppSetting::query()->pluck('value', 'key')->all());
        $default = self::DEFINITIONS[$key][1] ?? null;
        $value = $values[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        return is_int($default) ? (int) $value : $value;
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return collect(self::DEFINITIONS)->mapWithKeys(fn ($def, $key) => [$key => $this->get($key)])->all();
    }

    public function set(string $key, mixed $value): void
    {
        abort_unless(array_key_exists($key, self::DEFINITIONS), 422, "Unknown setting {$key}.");

        $old = $this->get($key);
        AppSetting::updateOrCreate(['key' => $key], ['value' => (string) $value, 'updated_by' => Auth::id()]);
        Cache::forget(self::CACHE_KEY);

        if ((string) $old !== (string) $value) {
            app(AuditLogger::class)->record(AppSetting::class, 'setting_changed', [$key => $old], [$key => $value]);
        }
    }
}
