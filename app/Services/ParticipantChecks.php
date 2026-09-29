<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\ParticipationStatus;
use App\Models\Activity;
use App\Models\ActivityParticipant;
use App\Models\Staff;
use App\Support\FinancialYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * FRD PT-04 (overlap conflicts) and PT-06 (cumulative field days).
 *
 * Field days for an activity are the participant's planned days where given,
 * otherwise the activity's days. Planned days count activities that are
 * approved or later; actual days count only attendance confirmed on
 * delivered activities (BR-07).
 */
class ParticipantChecks
{
    public function __construct(private AppSettings $settings) {}

    /**
     * Other approved or in-progress activities where the staff member's own
     * days overlap the given days (BR-04). A participant's own dates default
     * to their activity's.
     *
     * @return Collection<int, Activity>
     */
    public function conflicts(int $staffId, Carbon $start, Carbon $end, ?int $excludeActivityId = null): Collection
    {
        return Activity::query()
            ->status(ActivityStatus::Approved, ActivityStatus::InProgress)
            ->overlapping($start, $end)
            ->when($excludeActivityId, fn (Builder $q, $id) => $q->whereKeyNot($id))
            ->whereHas('participants', fn (Builder $q) => $q
                ->where('staff_id', $staffId)
                ->whereIn('status', ParticipationStatus::values(ParticipationStatus::Nominated, ParticipationStatus::Confirmed, ParticipationStatus::Attended))
                ->whereRaw('COALESCE(activity_participants.start_date, activities.start_date) <= ?', [$end->toDateString()])
                ->whereRaw('COALESCE(activity_participants.end_date, activities.end_date) >= ?', [$start->toDateString()]))
            ->orderBy('start_date')
            ->get(['id', 'reference', 'title', 'start_date', 'end_date', 'status']);
    }

    /**
     * Every staff member whose own days on another approved or in-progress
     * activity overlap the given days, in one query: staff id => references.
     * Used when choosing people from the whole staff list.
     *
     * @return array<int, list<string>>
     */
    public function conflictingStaff(Carbon $start, Carbon $end, ?int $excludeActivityId = null): array
    {
        return ActivityParticipant::query()
            ->join('activities', 'activities.id', '=', 'activity_participants.activity_id')
            ->whereNull('activities.deleted_at')
            ->whereNotNull('activity_participants.staff_id')
            ->whereIn('activities.status', ActivityStatus::values(ActivityStatus::Approved, ActivityStatus::InProgress))
            ->whereIn('activity_participants.status', ParticipationStatus::values(ParticipationStatus::Nominated, ParticipationStatus::Confirmed, ParticipationStatus::Attended))
            ->when($excludeActivityId, fn (Builder $q, $id) => $q->where('activities.id', '!=', $id))
            ->whereRaw('COALESCE(activity_participants.start_date, activities.start_date) <= ?', [$end->toDateString()])
            ->whereRaw('COALESCE(activity_participants.end_date, activities.end_date) >= ?', [$start->toDateString()])
            ->get(['activity_participants.staff_id', 'activities.reference'])
            ->groupBy('staff_id')
            ->map(fn ($rows) => $rows->pluck('reference')->unique()->values()->all())
            ->all();
    }

    /**
     * Conflicts for every staff participant on an activity, keyed by participant id.
     *
     * @return array<int, Collection<int, Activity>>
     */
    public function conflictsFor(Activity $activity): array
    {
        $activity->loadMissing('participants');

        return $activity->participants
            ->where('is_external', false)
            ->mapWithKeys(fn (ActivityParticipant $p) => [$p->id => $this->conflicts($p->staff_id, $p->startOn($activity), $p->endOn($activity), $activity->id)])
            ->filter(fn (Collection $c) => $c->isNotEmpty())
            ->all();
    }

    /**
     * Cumulative planned and actual field days for a staff member in a date window.
     *
     * @return array{planned: int, actual: int}
     */
    public function fieldDays(int $staffId, Carbon $from, Carbon $to, ?int $excludeActivityId = null): array
    {
        $rows = ActivityParticipant::query()
            ->where('staff_id', $staffId)
            ->whereIn('status', ParticipationStatus::values(ParticipationStatus::Nominated, ParticipationStatus::Confirmed, ParticipationStatus::Attended))
            ->whereHas('activity', fn (Builder $q) => $q
                ->whereIn('status', ActivityStatus::values(ActivityStatus::Approved, ActivityStatus::InProgress, ActivityStatus::Postponed, ActivityStatus::Completed, ActivityStatus::ReportReceived, ActivityStatus::Closed))
                ->whereDate('start_date', '>=', $from)
                ->whereDate('start_date', '<=', $to)
                ->when($excludeActivityId, fn (Builder $q, $id) => $q->whereKeyNot($id)))
            ->with('activity:id,days,status,start_date,end_date')
            ->get(['id', 'activity_id', 'status', 'start_date', 'end_date', 'days_planned', 'days_attended']);

        $planned = 0;
        $actual = 0;
        foreach ($rows as $row) {
            $planned += $row->plannedDays($row->activity);
            if ($row->activity->status->isDelivered() && $row->status === ParticipationStatus::Attended) {
                $actual += $row->days_attended ?? $row->plannedDays($row->activity);
            }
        }

        return ['planned' => $planned, 'actual' => $actual];
    }

    /**
     * Field-day position for the quarter and financial year containing the
     * activity, including this activity, against the configured thresholds.
     *
     * @return array{quarter: int, year: int, quarter_limit: int, year_limit: int, over_quarter: bool, over_year: bool}
     */
    public function fieldDayFlags(Staff|int $staff, Activity $activity, ?int $thisActivityDays = null): array
    {
        $staffId = $staff instanceof Staff ? $staff->id : $staff;
        $fy = FinancialYear::for($activity->start_date);
        [$qStart, $qEnd] = $fy->quarter(FinancialYear::quarterOf($activity->start_date));
        $own = $thisActivityDays ?? $activity->days;

        $quarter = $this->fieldDays($staffId, $qStart, $qEnd, $activity->id)['planned'] + $own;
        $year = $this->fieldDays($staffId, $fy->start(), $fy->end(), $activity->id)['planned'] + $own;
        $qLimit = $this->settings->int('field_days_threshold_quarter');
        $yLimit = $this->settings->int('field_days_threshold_year');

        return [
            'quarter' => $quarter,
            'year' => $year,
            'quarter_limit' => $qLimit,
            'year_limit' => $yLimit,
            'over_quarter' => $qLimit > 0 && $quarter > $qLimit,
            'over_year' => $yLimit > 0 && $year > $yLimit,
        ];
    }
}
