<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * FRD MD-05: the DSA rate per job grade, with effective dates. Past rates are
 * kept so an activity is costed at the rate in force on its start date (BR-02).
 *
 * Since 22 October 2026 there is one standard rate per grade, whatever the
 * destination: standard rates have no destination_category. Rows that do
 * (the retired Cities / Other towns rates) are history only and never used.
 * Casuals and attachees are on the INTERN rate unless HR gives them a job grade.
 *
 * Job grades are the scales in the HR staff register ("Job Scale" 1 to 10),
 * stored as the bare number ("6"), as the Board's memo template prints them.
 */
class DsaRate extends Model
{
    use Auditable;

    public const INTERN = 'INTERN';

    /**
     * The approved standard rates (KES per night or day) by scale, seeded on
     * every run by SettingsSeeder. Later changes are entered in Settings › DSA rates.
     *
     * @var array<string, string>
     */
    public const STANDARD = [
        '1' => '18200.00',
        '2' => '16800.00',
        '3' => '16800.00',
        '4' => '14000.00',
        '5' => '14000.00',
        '6' => '11200.00',
        '7' => '11200.00',
        '8' => '11200.00',
        '9' => '6300.00',
        '10' => '6300.00',
        self::INTERN => '4000.00',
    ];

    /** Start of FY 2026/27, the date the standard rates apply from. */
    public const STANDARD_FROM = '2026-07-01';

    protected $fillable = ['job_grade', 'destination_category', 'amount', 'effective_from', 'effective_to', 'is_provisional', 'recorded_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_provisional' => 'boolean',
        ];
    }

    /** @param  Builder<static>  $query */
    public function scopeInForceOn(Builder $query, Carbon $date): void
    {
        $query->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    /** @param  Builder<static>  $query */
    public function scopeStandard(Builder $query): void
    {
        $query->whereNull('destination_category');
    }

    public static function lookup(?string $jobGrade, Carbon $on): ?self
    {
        if (blank($jobGrade)) {
            return null;
        }

        // Grades recorded as "NGCDF 5" or "Scale 5" before scales were adopted still find scale 5.
        $grade = static::normaliseGrade($jobGrade) ?? strtoupper(trim($jobGrade));

        return static::query()
            ->standard()
            ->whereRaw('UPPER(job_grade) = ?', [$grade])
            ->inForceOn($on)
            ->orderByDesc('effective_from')
            ->first();
    }

    /** The grade DSA is costed at: the officer's own, or INTERN for a casual or attachee without one. */
    public static function gradeFor(?string $jobGrade, ?EmploymentType $type): ?string
    {
        if (filled($jobGrade)) {
            return $jobGrade;
        }

        return $type !== null && ! $type->requiresStaffNumber() ? self::INTERN : null;
    }

    /**
     * Reads a job grade as typed ("6", "Scale 6", "Job Group 6", "NGCDF 6",
     * "Intern") as the scale ("6") or INTERN. Returns null when it is not a
     * grade with a standard rate.
     *
     * @param  list<string>|null  $grades  DsaRate::grades(), when reading many rows
     */
    public static function normaliseGrade(?string $grade, ?array $grades = null): ?string
    {
        $text = strtoupper(trim(preg_replace('/\s+/', ' ', (string) $grade)));
        if ($text === self::INTERN) {
            return self::INTERN;
        }

        if (preg_match('/^(?:NGCDF|JOB GROUP|JOB SCALE|JOB GRADE|JG|SCALE|GRADE)?\s*(\d{1,2})(?:\.0+)?$/', $text, $m)) {
            $text = (string) (int) $m[1];
        }

        return in_array($text, $grades ?? static::grades(), true) ? $text : null;
    }

    /** @return list<string> every grade with a standard rate, approved or added since in Settings */
    public static function grades(): array
    {
        $added = static::query()->standard()->distinct()->pluck('job_grade')->map(fn ($g) => strtoupper($g))->all();

        return array_values(array_unique([...array_map('strval', array_keys(self::STANDARD)), ...$added]));
    }
}
