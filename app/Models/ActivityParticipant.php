<?php

namespace App\Models;

use App\Enums\ExternalCategory;
use App\Enums\ParticipantRole;
use App\Enums\ParticipationStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A staff member (or external person) on an activity. Department, designation
 * and office are a snapshot taken when they were added.
 */
class ActivityParticipant extends Model
{
    use Auditable;

    protected $fillable = [
        'activity_id',
        'staff_id',
        'is_external',
        'external_name',
        'external_organisation',
        'external_category',
        'department_id',
        'designation_id',
        'office_id',
        'job_grade',
        'role',
        'start_date',
        'end_date',
        'status',
        'days_planned',
        'days_attended',
        'remarks',
        'conflict_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_external' => 'boolean',
            'external_category' => ExternalCategory::class,
            'role' => ParticipantRole::class,
            'status' => ParticipationStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Designation, $this> */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /** @return BelongsTo<Office, $this> */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /** @return HasMany<ActivityCost, $this> */
    public function costs(): HasMany
    {
        return $this->hasMany(ActivityCost::class);
    }

    public function displayName(): string
    {
        return $this->is_external ? (string) $this->external_name : (string) $this->staff?->name;
    }

    /** First day this person is on the activity (their own date, else the activity's). */
    public function startOn(Activity $activity): Carbon
    {
        return $this->start_date ?? $activity->start_date;
    }

    public function endOn(Activity $activity): Carbon
    {
        return $this->end_date ?? $activity->end_date;
    }

    /** Planned days for this person: their own dates, else an explicit day count, else the whole activity. */
    public function plannedDays(Activity $activity): int
    {
        if ($this->start_date && $this->end_date) {
            return (int) $this->start_date->diffInDays($this->end_date) + 1;
        }

        return $this->days_planned ?? $activity->days;
    }

    /** Takes part on only some of the activity's days. */
    public function isPartial(Activity $activity): bool
    {
        return $this->plannedDays($activity) < $activity->days;
    }

    /** Copy the staff member's current department/designation/office onto this record. */
    public function snapshotFrom(Staff $staff): static
    {
        $this->department_id = $staff->department_id;
        $this->designation_id = $staff->designation_id;
        $this->office_id = $staff->office_id;
        $this->job_grade = $staff->job_grade;

        return $this;
    }
}
