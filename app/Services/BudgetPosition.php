<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityCost;
use App\Models\Department;
use App\Models\DepartmentBudget;
use App\Support\FinancialYear;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Each department's budget position for a financial year (business owner's
 * definitions, 21 October 2026). All figures are integer cents.
 *
 *  - Allocation: the department's budget for the year as the CEO approved it,
 *    with the adjustments the CEO has approved since (App\Services\Budgeting).
 *    A draft, or a budget awaiting approval, counts for nothing.
 *  - Pending: memos submitted and not yet decided (under review, awaiting
 *    decision, returned by the CEO), at their costed plan or, before costing,
 *    the department's estimate. Shown apart; never deducted.
 *  - Approved: what the CEO authorised, frozen at approval.
 *  - Spent: actual amounts entered from Finance returns, on activities
 *    approved or later and on cancelled ones (money already paid out).
 *  - Committed: the planned amount of every cost line on an activity approved
 *    or later that has no actual amount yet. A cancelled activity commits
 *    nothing, so its unspent plan is released.
 *  - Balance = Allocation − Spent − Committed. Each line counts once, as spent
 *    or as committed, never both.
 *  - Utilisation = (Spent + Committed) ÷ Allocation; Spent % = Spent ÷ Allocation.
 *  - Adjustments: the change in planned cost since approval (releases, team
 *    and cost amendments, extensions), and what cancellations released.
 *
 * An activity belongs to its requesting department and to the financial year
 * of its start date. Nothing here is actual expenditure unless Finance's
 * figure has been entered on the cost line.
 */
class BudgetPosition
{
    public function __construct(private MemoTracker $tracker, private Budgeting $budgeting) {}

    /**
     * One row per department with an allocation or activity in the year.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forYear(FinancialYear $fy, ?int $departmentId = null): Collection
    {
        $activities = $this->activities($fy, $departmentId);
        $allocations = $this->allocations($fy, $departmentId);

        $departmentIds = $allocations->keys()->merge($activities->pluck('organising_department_id')->map(fn ($id) => (int) $id))->unique();
        $departments = Department::query()->whereIn('id', $departmentIds->filter())->get()->keyBy('id');

        return $departmentIds
            ->map(fn (int $id) => $this->row(
                $departments->get($id),
                $allocations->get($id),
                $activities->filter(fn (Activity $a) => (int) $a->organising_department_id === $id),
            ))
            ->sortBy(fn (array $r) => $r['department']?->name ?? '~')
            ->values();
    }

    /**
     * Board-wide totals of forYear() rows.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int|null>
     */
    public function totals(Collection $rows): array
    {
        $sum = fn (string $key) => (int) $rows->sum($key);
        $allocated = $rows->contains(fn ($r) => $r['allocation'] !== null);
        $allocation = $sum('allocation');

        return [
            'allocation' => $allocated ? $allocation : null,
            'pending' => $sum('pending'),
            'pending_count' => $sum('pending_count'),
            'approved' => $sum('approved'),
            'approved_count' => $sum('approved_count'),
            'committed' => $sum('committed'),
            'spent' => $sum('spent'),
            'adjustments' => $sum('adjustments'),
            'released' => $sum('released'),
            'balance' => $allocated ? $allocation - $sum('spent') - $sum('committed') : null,
            'utilisation' => $this->percent($sum('spent') + $sum('committed'), $allocated ? $allocation : null),
            'spent_percent' => $this->percent($sum('spent'), $allocated ? $allocation : null),
        ];
    }

    /**
     * One department's row for the year, empty figures when it has neither an
     * allocation nor activity. What a Head of Department sees of their own
     * department; never another department's.
     *
     * @return array<string, mixed>
     */
    public function forDepartment(FinancialYear $fy, int $departmentId): array
    {
        return ($this->forYear($fy, $departmentId)->first() ?? $this->row(Department::query()->find($departmentId), null, new EloquentCollection))
            + ['financial_year' => $fy->label()];
    }

    /**
     * The requesting department's position in the activity's year, and what
     * the balance would be if this activity's plan were approved as it stands.
     *
     * @return array<string, mixed>
     */
    public function forActivity(Activity $activity): array
    {
        $fy = FinancialYear::for($activity->start_date);
        $row = $this->forYear($fy, $activity->organising_department_id)->first()
            ?? $this->row($activity->organisingDepartment, null, new EloquentCollection);

        // Already counted when approved or later; otherwise this is the request being decided.
        $counted = $activity->status->isApprovedOrLater();
        $request = $counted ? 0 : $this->tracker->proposedCents($activity);

        $isPending = in_array($activity->status, $this->pendingStatuses(), true);

        return $row + [
            'financial_year' => $fy->label(),
            'this_request' => $this->tracker->proposedCents($activity),
            'other_pending' => $row['pending'] - ($isPending ? $this->tracker->proposedCents($activity) : 0),
            'already_counted' => $counted,
            'balance_after' => $row['balance'] === null ? null : $row['balance'] - $request,
        ];
    }

    /**
     * What sits behind a department's row: approved activities and their
     * money, pending memos, and adjustments since approval.
     *
     * @return array{approved: Collection<int, array<string, mixed>>, pending: Collection<int, array<string, mixed>>, cancelled: Collection<int, array<string, mixed>>}
     */
    public function detail(FinancialYear $fy, int $departmentId): array
    {
        $activities = $this->activities($fy, $departmentId);

        $money = fn (Activity $a) => [
            'activity' => $a,
            'approved' => Money::toCents($a->approved_estimated_total ?? $a->estimated_total),
            'planned' => Money::toCents($a->estimated_total),
            'spent' => $this->spentCents($a),
            'committed' => $this->committedCents($a),
            'adjustment' => Money::toCents($a->estimated_total) - Money::toCents($a->approved_estimated_total ?? $a->estimated_total),
        ];

        return [
            'approved' => $activities->filter(fn (Activity $a) => $a->status->isApprovedOrLater())->map($money)->values(),
            'pending' => $activities->filter(fn (Activity $a) => in_array($a->status, $this->pendingStatuses(), true))
                ->map(fn (Activity $a) => ['activity' => $a, 'requested' => $this->tracker->proposedCents($a)])->values(),
            'cancelled' => $activities->filter(fn (Activity $a) => $a->status === ActivityStatus::Cancelled && $a->approved_at !== null)
                ->map(fn (Activity $a) => $money($a) + ['released' => $this->committedIfLive($a)])->values(),
        ];
    }

    /** @return list<ActivityStatus> */
    public function pendingStatuses(): array
    {
        return [ActivityStatus::UnderReview, ActivityStatus::AwaitingDecision, ActivityStatus::Returned];
    }

    /**
     * @param  EloquentCollection<int, Activity>|Collection<int, Activity>  $activities
     * @return array<string, mixed>
     */
    private function row(?Department $department, ?int $allocation, Collection $activities): array
    {
        $approved = $activities->filter(fn (Activity $a) => $a->status->isApprovedOrLater());
        $cancelled = $activities->filter(fn (Activity $a) => $a->status === ActivityStatus::Cancelled);
        $pending = $activities->filter(fn (Activity $a) => in_array($a->status, $this->pendingStatuses(), true));

        $spent = $approved->concat($cancelled)->sum(fn (Activity $a) => $this->spentCents($a));
        $committed = $approved->sum(fn (Activity $a) => $this->committedCents($a));

        return [
            'department' => $department,
            'allocation' => $allocation,
            'pending' => $pending->sum(fn (Activity $a) => $this->tracker->proposedCents($a)),
            'pending_count' => $pending->count(),
            'approved' => $approved->sum(fn (Activity $a) => Money::toCents($a->approved_estimated_total ?? $a->estimated_total)),
            'approved_count' => $approved->count(),
            'committed' => $committed,
            'spent' => $spent,
            'adjustments' => $approved->sum(fn (Activity $a) => Money::toCents($a->estimated_total) - Money::toCents($a->approved_estimated_total ?? $a->estimated_total)),
            'released' => $cancelled->filter(fn (Activity $a) => $a->approved_at !== null)->sum(fn (Activity $a) => $this->committedIfLive($a)),
            'balance' => $allocation === null ? null : $allocation - $spent - $committed,
            'utilisation' => $this->percent($spent + $committed, $allocation),
            'spent_percent' => $this->percent($spent, $allocation),
        ];
    }

    private function spentCents(Activity $activity): int
    {
        return $activity->costs->whereNotNull('actual_amount')->sum(fn (ActivityCost $c) => Money::toCents($c->actual_amount));
    }

    private function committedCents(Activity $activity): int
    {
        return $activity->status->isApprovedOrLater() ? $this->committedIfLive($activity) : 0;
    }

    /** Planned amount of the lines with no actual yet. */
    private function committedIfLive(Activity $activity): int
    {
        return $activity->costs->whereNull('actual_amount')->sum(fn (ActivityCost $c) => Money::toCents($c->estimated_amount));
    }

    /** @return EloquentCollection<int, Activity> */
    private function activities(FinancialYear $fy, ?int $departmentId): EloquentCollection
    {
        return Activity::query()
            ->whereBetween('start_date', [$fy->start()->toDateString(), $fy->end()->toDateString()])
            ->status(...$this->tracker->approvedStatuses(), ...$this->pendingStatuses(), ...[ActivityStatus::Cancelled])
            ->when($departmentId, fn (Builder $q, $id) => $q->where('organising_department_id', $id))
            ->with(['costs:id,activity_id,estimated_amount,actual_amount', 'organisingDepartment'])
            ->orderBy('start_date')
            ->get();
    }

    /** @return Collection<int, int> department id => allocation in cents */
    private function allocations(FinancialYear $fy, ?int $departmentId): Collection
    {
        return DepartmentBudget::query()->approved()
            ->where('financial_year', $fy->label())
            ->when($departmentId, fn (Builder $q, $id) => $q->where('department_id', $id))
            ->get()
            ->groupBy(fn (DepartmentBudget $b) => (int) $b->department_id)
            ->map(fn ($budgets) => (int) $budgets->sum(fn (DepartmentBudget $b) => $this->budgeting->totals($b)['current']));
    }

    private function percent(int $part, ?int $whole): ?int
    {
        return $whole === null || $whole <= 0 ? null : intdiv($part * 1000 + 5 * $whole, $whole * 10);
    }
}
