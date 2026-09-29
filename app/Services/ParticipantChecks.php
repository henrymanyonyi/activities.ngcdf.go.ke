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
     * Other approved or in-progress activities the staff member is on with overlapping dates (BR-04).
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
                ->whereIn('status', ParticipationStatus::values(ParticipationStatus::Nominated, ParticipationStatus::Confirmed, ParticipationStatus::Attended)))
            ->orderBy('start_date')
            ->get(['id', 'reference', 'title', 'start_date', 'end_date', 'status']);
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
            ->mapWithKeys(fn (ActivityParticipant $p) => [$p->id => $this->conflicts($p->staff_id, $activity->start_date, $activity->end_date, $activity->id)])
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
            ->with('activity:id,days,status')
            ->get(['id', 'activity_id', 'status', 'days_planned', 'days_attended']);

        $planned = 0;
        $actual = 0;
        foreach ($rows as $row) {
            $planned += $row->days_planned ?? $row->activity->days;
            if ($row->activity->status->isDelivered() && $row->status === ParticipationStatus::Attended) {
                $actual += $row->days_attended ?? $row->days_planned ?? $row->activity->days;
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
