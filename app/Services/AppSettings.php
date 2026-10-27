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
        'report_due_working_days' => ['Report due period (working days)', 7, 'The activity report is due to the CEO this many working days after the end date (OI-07; 7 at the business owner\'s request, 21 October 2026).'],
        'field_days_threshold_quarter' => ['Field days per officer per quarter', 30, 'Flag an officer whose cumulative field days would exceed this in a quarter (OI-05).'],
        'field_days_threshold_year' => ['Field days per officer per financial year', 100, 'Flag an officer whose cumulative field days would exceed this in a financial year (OI-05).'],
        'tolerance_date_shift_days' => ['Date change tolerance (days)', 2, 'After approval, moving the dates by more than this returns the activity to the CEO (BR-10).'],
        'tolerance_extension_days' => ['Extension tolerance (days)', 1, 'After approval, extending by more than this returns the activity to the CEO (EX-04).'],
        'tolerance_participant_changes' => ['Participant change tolerance (people)', 1, 'After approval, adding or removing more than this many people returns the activity to the CEO (PT-07).'],
        'tolerance_cost_increase_percent' => ['Cost increase tolerance (%)', 10, 'After approval, a planned-cost increase above this percentage returns the activity to the CEO (BR-10).'],
        'dsa_basis' => ['DSA basis', 'nights', 'Whether planned DSA is rate × nights or rate × days (OI-01): "nights" or "days".'],
        'acos_forwards_via_cos' => ['Assistant\'s memos go through the Chief of Staff', 0, 'Yes: a memo the Assistant Chief of Staff forwards goes to the Chief of Staff before the CEO. No: the Assistant may forward it straight to the CEO.'],
        'memo_review_reminder_working_days' => ['Memo pending reminder (working days)', 3, 'A memo still under review or awaiting the CEO\'s decision after this many working days is flagged as pending too long and raises a daily reminder.'],
        'finance_department_id' => ['Finance department (imprest instructions)', 0, 'The department whose Head receives the CEO\'s instruction to process the imprest for an approved memo.'],
        'procurement_department_id' => ['Procurement department (ticket instructions)', 0, 'The department whose Head receives the CEO\'s instruction to procure the travel tickets for an approved memo.'],
        'alerts_email' => ['Email when action is needed', 1, 'Yes: Heads of Department, the Chief of Staff and the Assistant also get an email when a memo, report or instruction needs them. It gives the reference only; the details stay in OCEO.'],
        'alerts_sms' => ['SMS when action is needed', 1, 'Yes: they also get an SMS through SMS Leopard, to the mobile number on their account or on the staff list. It gives the reference only.'],
    ];

    /** Settings that are a yes (1) or no (0) choice. */
    public const SWITCHES = ['acos_forwards_via_cos', 'alerts_email', 'alerts_sms'];

    /** Settings that name a department; 0 means the default (App\Enums\InstructionKind::defaultDepartment()). */
    public const DEPARTMENTS = ['finance_department_id', 'procurement_department_id'];

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

    public function bool(string $key): bool
    {
        return (bool) $this->int($key);
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
