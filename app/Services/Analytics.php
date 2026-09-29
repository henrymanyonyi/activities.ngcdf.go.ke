<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\ParticipationStatus;
use App\Models\Activity;
use App\Models\ActivityCost;
use App\Models\ActivityLocation;
use App\Models\ActivityParticipant;
use App\Models\BudgetLine;
use App\Models\Constituency;
use App\Models\County;
use App\Models\Department;
use App\Models\Region;
use App\Models\Staff;
use App\Support\FinancialYear;
use App\Support\Money;
use App\Support\Period;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Aggregates behind the dashboards and reports (FRD 7.9, Section 10). All
 * money is integer cents. "Countable" activities are those approved in or
 * outside the system and not declined; cancelled activities still count
 * toward spend already incurred but not toward participation.
 */
class Analytics
{
    public function __construct(private ActivityCosting $costing, private ParticipantChecks $checks, private AppSettings $settings) {}

    /**
     * Activities starting in the period, with what the aggregates need.
     *
     * @param  array<string, mixed>  $filters  department_id, region_id, activity_type_id
     * @return EloquentCollection<int, Activity>
     */
    public function activities(Period $period, array $filters = [], ?array $statuses = null): EloquentCollection
    {
        return Activity::query()
            ->whereBetween('start_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->when($statuses, fn (Builder $q, $s) => $q->status(...$s))
            ->when($filters['department_id'] ?? null, fn (Builder $q, $id) => $q->where('organising_department_id', $id))
            ->when($filters['activity_type_id'] ?? null, fn (Builder $q, $id) => $q->where('activity_type_id', $id))
            ->when($filters['region_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('locations', fn (Builder $l) => $l->where('region_id', $id)))
            ->with(['organisingDepartment', 'costs.category', 'participants.staff.department', 'participants.department', 'type', 'locations.county', 'locations.region', 'locations.constituency'])
            ->orderBy('start_date')
            ->get();
    }

    /** @return list<ActivityStatus> */
    public static function participationStatuses(): array
    {
        return [ActivityStatus::Approved, ActivityStatus::InProgress, ActivityStatus::Postponed, ActivityStatus::Completed, ActivityStatus::ReportReceived, ActivityStatus::Closed];
    }

    /** @return list<ActivityStatus> */
    public static function spendStatuses(): array
    {
        return [...self::participationStatuses(), ActivityStatus::Cancelled];
    }

    /**
     * DSA / travel / other / total for a set of cost lines, planned and best-known.
     *
     * @param  Collection<int, ActivityCost>  $lines
     * @return array{dsa: int, travel: int, other: int, total: int, planned: int, actual: int}
     */
    public function split(Collection $lines): array
    {
        $out = ['dsa' => 0, 'travel' => 0, 'other' => 0, 'total' => 0, 'planned' => 0, 'actual' => 0];

        foreach ($lines as $line) {
            $best = $this->costing->bestCents($line);
            $out[$line->category?->group() ?? 'other'] += $best;
            $out['total'] += $best;
            $out['planned'] += Money::toCents($line->estimated_amount);
            $out['actual'] += Money::toCents($line->actual_amount);
        }

        return $out;
    }

    /**
     * DB-02 (concept Staff Summary): one row per officer who took part in the period.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function staffParticipation(Period $period, array $filters = []): Collection
    {
        $activities = $this->activities($period, [], self::participationStatuses());
        $fy = FinancialYear::current();
        [$qStart, $qEnd] = $fy->quarter(FinancialYear::quarterOf(today()));

        $rows = [];
        foreach ($activities as $activity) {
            $breakdown = $this->costing->breakdown($activity)['rows']->keyBy(fn ($r) => $r['participant']->id);

            foreach ($activity->participants->where('is_external', false) as $p) {
                if (! empty($filters['department_id']) && (int) $p->staff?->department_id !== (int) $filters['department_id']) {
                    continue;
                }

                $id = $p->staff_id;
                $rows[$id] ??= [
                    'staff' => $p->staff,
                    'activities' => collect(),
                    'planned_days' => 0, 'actual_days' => 0, 'attended' => 0, 'missed' => 0,
                    'dsa' => 0, 'travel' => 0, 'other' => 0, 'share' => 0, 'total' => 0,
                ];

                $own = $activity->costs->where('activity_participant_id', $p->id);
                $split = $this->split($own);
                $share = $breakdown[$p->id]['share'] ?? 0;

                $rows[$id]['activities']->push(['activity' => $activity, 'participant' => $p, 'cost' => $split['total'] + $share]);
                $rows[$id]['planned_days'] += $p->days_planned ?? $activity->days;
                if ($p->status === ParticipationStatus::Attended) {
                    $rows[$id]['attended']++;
                    $rows[$id]['actual_days'] += $p->days_attended ?? $p->days_planned ?? $activity->days;
                }
                if ($p->status === ParticipationStatus::Absent) {
                    $rows[$id]['missed']++;
                }
                $rows[$id]['dsa'] += $split['dsa'];
                $rows[$id]['travel'] += $split['travel'];
                $rows[$id]['other'] += $split['other'];
                $rows[$id]['share'] += $share;
                $rows[$id]['total'] += $split['total'] + $share;
            }
        }

        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        return collect($rows)
            ->filter(fn ($r) => $search === '' || str_contains(mb_strtolower((string) $r['staff']?->name), $search) || str_contains(mb_strtolower((string) $r['staff']?->staff_number), $search))
            ->map(function ($r) use ($fy, $qStart, $qEnd) {
                $r['count'] = $r['activities']->count();
                $r['last'] = $r['activities']->max(fn ($a) => $a['activity']->start_date);
                $r['by_type'] = $r['activities']->groupBy(fn ($a) => $a['activity']->type?->name ?? 'Unspecified')->map->count()->sortDesc();
                $r['quarter_days'] = $this->checks->fieldDays($r['staff']->id, $qStart, $qEnd)['planned'];
                $r['year_days'] = $this->checks->fieldDays($r['staff']->id, $fy->start(), $fy->end())['planned'];
                $r['quarter_limit'] = $this->settings->int('field_days_threshold_quarter');
                $r['year_limit'] = $this->settings->int('field_days_threshold_year');

                return $r;
            })
            ->sortByDesc('planned_days')
            ->values();
    }

    /**
     * Staff on the list with no participation in the period (the other half of "who is participating").
     *
     * @param  list<int>  $participatingIds
     * @return EloquentCollection<int, Staff>
     */
    public function nonParticipants(array $participatingIds, array $filters = []): EloquentCollection
    {
        return Staff::query()->active()
            ->whereNotIn('id', $participatingIds)
            ->when($filters['department_id'] ?? null, fn (Builder $q, $id) => $q->where('department_id', $id))
            ->with('department')
            ->orderBy('name')
            ->get();
    }

    /**
     * DB-03 (concept Department Costs), grouped by requesting department or
     * by the officer's department, with budget and variance where recorded.
     *
     * @param  'requesting'|'officer'  $lens
     * @return Collection<int, array<string, mixed>>
     */
    public function departmentCosts(Period $period, string $lens = 'requesting'): Collection
    {
        $activities = $this->activities($period, [], self::spendStatuses());
        $rows = [];

        $blank = fn (?Department $d) => [
            'department' => $d, 'staff' => [], 'activities' => [], 'days' => 0,
            'dsa' => 0, 'travel' => 0, 'other' => 0, 'total' => 0, 'planned' => 0, 'actual' => 0,
        ];

        foreach ($activities as $activity) {
            if ($lens === 'requesting') {
                $key = $activity->organising_department_id ?? 0;
                $rows[$key] ??= $blank($activity->organisingDepartment);
                $split = $this->split($activity->costs);
                $rows[$key]['activities'][$activity->id] = true;
                foreach ($activity->participants->where('is_external', false) as $p) {
                    $rows[$key]['staff'][$p->staff_id] = true;
                    $rows[$key]['days'] += $p->days_planned ?? $activity->days;
                }
                foreach (['dsa', 'travel', 'other', 'total', 'planned', 'actual'] as $k) {
                    $rows[$key][$k] += $split[$k];
                }

                continue;
            }

            // Officer lens: each participant's own lines, attributed to the department they were in at the time.
            foreach ($activity->participants->where('is_external', false) as $p) {
                $key = $p->department_id ?? 0;
                $rows[$key] ??= $blank($p->department);
                $split = $this->split($activity->costs->where('activity_participant_id', $p->id));
                $rows[$key]['activities'][$activity->id] = true;
                $rows[$key]['staff'][$p->staff_id] = true;
                $rows[$key]['days'] += $p->days_planned ?? $activity->days;
                foreach (['dsa', 'travel', 'other', 'total', 'planned', 'actual'] as $k) {
                    $rows[$key][$k] += $split[$k];
                }
            }
        }

        $budgets = BudgetLine::query()
            ->where('financial_year', FinancialYear::for($period->from)->label())
            ->get()
            ->groupBy('department_id')
            ->map(fn ($lines) => $lines->sum(fn ($l) => Money::toCents($l->amount)));

        return collect($rows)->map(function ($r, $key) use ($budgets, $lens) {
            $r['staff_count'] = count($r['staff']);
            $r['activity_count'] = count($r['activities']);
            $r['budget'] = $lens === 'requesting' ? ($budgets[$key] ?? null) : null;
            $r['variance'] = $r['budget'] === null ? null : $r['budget'] - $r['total'];
            unset($r['staff'], $r['activities']);

            return $r;
        })->sortByDesc('total')->values();
    }

    /**
     * DB-04: activities, days and cost planned against delivered, by department and quarter.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function plannedVsActual(Period $period): Collection
    {
        $activities = $this->activities($period, [], [...self::spendStatuses()]);

        return $activities
            ->groupBy(fn (Activity $a) => ($a->organisingDepartment?->name ?? 'Unassigned').'|Q'.FinancialYear::quarterOf($a->start_date))
            ->map(function (Collection $group, string $key) {
                [$department, $quarter] = explode('|', $key);
                $delivered = $group->filter(fn (Activity $a) => $a->status->isDelivered());

                return [
                    'department' => $department,
                    'quarter' => $quarter,
                    'planned' => $group->count(),
                    'delivered' => $delivered->count(),
                    'postponed' => $group->where('status', ActivityStatus::Postponed)->count(),
                    'cancelled' => $group->where('status', ActivityStatus::Cancelled)->count(),
                    'planned_days' => $group->sum('days'),
                    'actual_days' => $delivered->sum(fn (Activity $a) => $a->participants->where('status', ParticipationStatus::Attended)->sum(fn ($p) => $p->days_attended ?? $a->days)),
                    'planned_cost' => $group->sum(fn (Activity $a) => Money::toCents($a->estimated_total)),
                    'actual_cost' => $delivered->sum(fn (Activity $a) => Money::toCents($a->actual_total)),
                ];
            })
            ->map(fn ($r) => $r + ['variance' => $r['planned_cost'] - $r['actual_cost'], 'delivery_rate' => $r['planned'] > 0 ? intdiv($r['delivered'] * 100, $r['planned']) : 0])
            ->sortBy(['department', 'quarter'])
            ->values();
    }

    /**
     * DB-05: regions, counties and constituencies visited in the period, and those not visited.
     *
     * @return array{regions: Collection, counties: Collection, constituencies: Collection}
     */
    public function coverage(Period $period): array
    {
        $visited = ActivityLocation::query()
            ->whereHas('activity', fn (Builder $q) => $q
                ->status(ActivityStatus::InProgress, ActivityStatus::Completed, ActivityStatus::ReportReceived, ActivityStatus::Closed)
                ->whereBetween('start_date', [$period->from->toDateString(), $period->to->toDateString()]))
            ->get(['region_id', 'county_id', 'constituency_id']);

        $regionIds = $visited->pluck('region_id')->filter()->unique();
        $countyIds = $visited->pluck('county_id')->filter()->unique();
        $constituencyIds = $visited->pluck('constituency_id')->filter()->unique();

        return [
            'regions' => Region::query()->orderBy('name')->get()->map(fn ($r) => ['name' => $r->name, 'visited' => $regionIds->contains($r->id)]),
            'counties' => County::query()->orderBy('name')->get()->map(fn ($c) => ['name' => $c->name, 'visited' => $countyIds->contains($c->id)]),
            'constituencies' => Constituency::query()->with('county')->orderBy('name')->get()->map(fn ($c) => ['name' => $c->name, 'county' => $c->county?->name, 'visited' => $constituencyIds->contains($c->id)]),
        ];
    }

    /**
     * EX-05: every officer currently on an activity, with location and return date.
     *
     * @return EloquentCollection<int, ActivityParticipant>
     */
    public function inTheFieldToday(): EloquentCollection
    {
        return ActivityParticipant::query()
            ->where('is_external', false)
            ->whereIn('status', ParticipationStatus::values(ParticipationStatus::Nominated, ParticipationStatus::Confirmed, ParticipationStatus::Attended))
            ->whereHas('activity', fn (Builder $q) => $q->status(ActivityStatus::InProgress, ActivityStatus::Approved)->overlapping(today(), today()))
            ->with(['staff.department', 'activity.locations.county', 'activity.locations.constituency', 'activity.locations.region'])
            ->get()
            ->sortBy(fn (ActivityParticipant $p) => $p->staff?->name)
            ->values();
    }
}
