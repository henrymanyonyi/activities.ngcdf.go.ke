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
 * Availability (business owner's decision, 21 October 2026): an officer is
 * unavailable on the days they hold on an activity that is approved or later
 * (approved, in progress, postponed, completed, report received, closed),
 * counting their own days on it, so a release that ends their assignment
 * early frees them from the next day. Only the Office of the CEO may still
 * place them with a written reason; a Head of Department may not. Memos under
 * review, awaiting decision or returned by the CEO are a warning only;
 * drafts, declined and cancelled activities hold nobody.
 *
 * Field days for an activity are the participant's planned days where given,
 * otherwise the activity's days. Planned days count activities that are
 * approved or later; actual days count only attendance confirmed on
 * delivered activities (BR-07).
 */
class ParticipantChecks
{
    public function __construct(private AppSettings $settings) {}

    /** @return list<ActivityStatus> statuses whose team is unavailable to any other activity */
    public static function blockingStatuses(): array
    {
        return [ActivityStatus::Approved, ActivityStatus::InProgress, ActivityStatus::Postponed, ActivityStatus::Completed, ActivityStatus::ReportReceived, ActivityStatus::Closed];
    }

    /** @return list<ActivityStatus> submitted and undecided: overlaps are a warning */
    public static function pendingStatuses(): array
    {
        return [ActivityStatus::UnderReview, ActivityStatus::AwaitingDecision, ActivityStatus::Returned];
    }

    /**
     * Other activities, approved or later, where the staff member's own days
     * overlap the given days (BR-04). A participant's own dates default to
     * their activity's.
     *
     * @return Collection<int, Activity>
     */
    public function conflicts(int $staffId, Carbon $start, Carbon $end, ?int $excludeActivityId = null): Collection
    {
        return Activity::query()
            ->status(...self::blockingStatuses())
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
     * Every staff member whose own days on another activity, approved or
     * later, overlap the given days, in one query: staff id => references.
     * Used when choosing people from the whole staff list.
     *
     * @return array<int, list<string>>
     */
    public function conflictingStaff(Carbon $start, Carbon $end, ?int $excludeActivityId = null): array
    {
        return collect($this->busyStaff($start, $end, self::blockingStatuses(), $excludeActivityId))
            ->map(fn (array $periods) => collect($periods)->pluck('reference')->unique()->values()->all())
            ->all();
    }

    /**
     * Why each staff member is unavailable on the given days: staff id =>
     * their own days on each activity, approved or later, that holds them.
     *
     * @return array<int, list<array{reference: string, from: Carbon, to: Carbon}>>
     */
    public function unavailableStaff(Carbon $start, Carbon $end, ?int $excludeActivityId = null): array
    {
        return $this->busyStaff($start, $end, self::blockingStatuses(), $excludeActivityId);
    }

    /**
     * Staff on a submitted, undecided memo on overlapping days: a warning
     * that the officer may become unavailable if that memo is approved.
     *
     * @return array<int, list<array{reference: string, from: Carbon, to: Carbon}>>
     */
    public function pendingStaff(Carbon $start, Carbon $end, ?int $excludeActivityId = null): array
    {
        return $this->busyStaff($start, $end, self::pendingStatuses(), $excludeActivityId);
    }

    /**
     * One plain sentence on why an officer cannot be placed, with the days
     * they are held. The other activity is named only to the Office of the
     * CEO; a Head of Department is not told whose activity it is.
     *
     * @param  list<array{reference: string, from: Carbon, to: Carbon}>  $periods
     */
    public static function unavailableReason(string $name, array $periods, bool $nameActivities): string
    {
        $spans = collect($periods)->map(fn (array $p) => sprintf('%s to %s%s', $p['from']->format('d M Y'), $p['to']->format('d M Y'), $nameActivities ? " ({$p['reference']})" : ''))->unique()->implode(', ');

        return "{$name} is not available: already on another approved activity from {$spans}.";
    }

    /**
     * Take a lock on the staff rows for the rest of the transaction, so two
     * people placing the same officer at the same moment are checked one
     * after the other and cannot both succeed.
     *
     * @param  list<int>  $staffIds
     */
    public function lockStaff(array $staffIds): void
    {
        if ($staffIds !== []) {
            Staff::query()->whereIn('id', $staffIds)->orderBy('id')->lockForUpdate()->pluck('id');
        }
    }

    /**
     * @param  list<ActivityStatus>  $statuses
     * @return array<int, list<array{reference: string, from: Carbon, to: Carbon}>>
     */
    private function busyStaff(Carbon $start, Carbon $end, array $statuses, ?int $excludeActivityId): array
    {
        return ActivityParticipant::query()
            ->join('activities', 'activities.id', '=', 'activity_participants.activity_id')
            ->whereNull('activities.deleted_at')
            ->whereNotNull('activity_participants.staff_id')
            ->whereIn('activities.status', ActivityStatus::values(...$statuses))
            ->whereIn('activity_participants.status', ParticipationStatus::values(ParticipationStatus::Nominated, ParticipationStatus::Confirmed, ParticipationStatus::Attended))
            ->when($excludeActivityId, fn (Builder $q, $id) => $q->where('activities.id', '!=', $id))
            ->whereRaw('COALESCE(activity_participants.start_date, activities.start_date) <= ?', [$end->toDateString()])
            ->whereRaw('COALESCE(activity_participants.end_date, activities.end_date) >= ?', [$start->toDateString()])
            ->orderBy('activities.start_date')
            ->selectRaw('activity_participants.staff_id, activities.reference, COALESCE(activity_participants.start_date, activities.start_date) as busy_from, COALESCE(activity_participants.end_date, activities.end_date) as busy_to')
            ->toBase()
            ->get()
            ->groupBy('staff_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => ['reference' => (string) $r->reference, 'from' => Carbon::parse($r->busy_from), 'to' => Carbon::parse($r->busy_to)])->values()->all())
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
            ->whereNotNull('staff_id')
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
     * Planned field days per staff member in a date window, for everyone at
     * once (same rules as fieldDays()): staff id => days. Lets the person
     * choosing a team see who has been in the field least (PT-06).
     *
     * @return array<int, int>
     */
    public function plannedFieldDaysByStaff(Carbon $from, Carbon $to, ?int $excludeActivityId = null): array
    {
        return ActivityParticipant::query()
            ->join('activities', 'activities.id', '=', 'activity_participants.activity_id')
            ->whereNull('activities.deleted_at')
            ->whereNotNull('activity_participants.staff_id')
            ->whereIn('activity_participants.status', ParticipationStatus::values(ParticipationStatus::Nominated, ParticipationStatus::Confirmed, ParticipationStatus::Attended))
            ->whereIn('activities.status', ActivityStatus::values(ActivityStatus::Approved, ActivityStatus::InProgress, ActivityStatus::Postponed, ActivityStatus::Completed, ActivityStatus::ReportReceived, ActivityStatus::Closed))
            ->whereDate('activities.start_date', '>=', $from)
            ->whereDate('activities.start_date', '<=', $to)
            ->when($excludeActivityId, fn (Builder $q, $id) => $q->where('activities.id', '!=', $id))
            ->groupBy('activity_participants.staff_id')
            ->selectRaw('activity_participants.staff_id, SUM(COALESCE(activity_participants.days_planned, activities.days)) as days')
            ->pluck('days', 'staff_id')
            ->map(fn ($days) => (int) $days)
            ->all();
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
