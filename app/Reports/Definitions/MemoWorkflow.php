<?php

namespace App\Reports\Definitions;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Reports\Report;
use App\Services\MemoTracker;
use App\Support\Money;
use App\Support\Period;
use Illuminate\Support\Collection;

/**
 * Activity memos originated in the period: where each is, who prepared it for
 * whom, how long it spent with the department, in review and with the CEO,
 * and its budget. One row per memo, so resubmissions are never counted twice.
 */
class MemoWorkflow extends Report
{
    public function __construct(private MemoTracker $tracker) {}

    public function key(): string
    {
        return 'memo-workflow';
    }

    public function title(): string
    {
        return 'Activity Memo Workflow';
    }

    public function description(): string
    {
        return 'Memos originated in the period, their stage, time at each stage, reviewers and budget.';
    }

    public function icon(): string
    {
        return 'fa-envelope-open-text';
    }

    public function filters(): array
    {
        return ['period', 'department'];
    }

    public function columns(): array
    {
        return [
            'reference' => ['Reference', 'text'],
            'title' => ['Memo', 'text'],
            'department' => ['Department', 'text'],
            'hod' => ['Head of Department', 'text'],
            'prepared_by' => ['Prepared by', 'text'],
            'status' => ['Status', 'text'],
            'with' => ['With', 'text'],
            'submitted' => ['Submitted', 'date'],
            'department_days' => ['Days with department', 'text'],
            'review_days' => ['Days in review', 'text'],
            'ceo_days' => ['Days with CEO', 'text'],
            'pending' => ['Pending too long', 'text'],
            'requested' => ['Department estimate', 'money'],
            'costed' => ['Costed plan', 'money'],
            'approved' => ['Approved amount', 'money'],
            'decided' => ['Decided', 'date'],
        ];
    }

    public function rows(array $filters): Collection
    {
        $period = Period::fromFilters($filters);

        $memos = Activity::query()
            ->whereBetween('created_at', [$period->from, $period->to])
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('organising_department_id', $id))
            ->with(['organisingDepartment', 'creator', 'responsibleHod', 'statusHistory'])
            ->orderBy('created_at')
            ->get();

        $since = $this->tracker->lastMoves($memos->pluck('id'));

        return $memos->map(function (Activity $a) use ($since) {
            $stages = $this->tracker->stageDurations($a->statusHistory);
            $pending = in_array($a->status, [ActivityStatus::UnderReview, ActivityStatus::AwaitingDecision], true)
                && isset($since[$a->id]) && $this->tracker->isTooLong($since[$a->id]);

            return [
                'reference' => $a->reference,
                'title' => $a->title,
                'department' => $a->organisingDepartment?->name,
                'hod' => $a->responsibleHodLabel(),
                'prepared_by' => ($a->creator?->name ?? $a->submitted_by_name).($a->isPreparedOnBehalf() ? ' (on behalf)' : ''),
                'status' => $a->status->label(),
                'with' => $a->status->isPendingDecision() || $a->status->isWithDepartment() ? $a->currentHolder() : null,
                'submitted' => $stages['submitted_at'],
                'department_days' => number_format($stages['department'], 1),
                'review_days' => number_format($stages['review'], 1),
                'ceo_days' => number_format($stages['ceo'], 1),
                'pending' => $pending ? 'Yes, since '.$since[$a->id]->format('d M Y') : null,
                'requested' => $a->requested_budget !== null ? Money::toCents($a->requested_budget) : null,
                'costed' => Money::toCents($a->estimated_total),
                'approved' => $a->status->isApprovedOrLater() ? Money::toCents($a->approved_estimated_total ?? $a->estimated_total) : null,
                'decided' => $stages['decided_at'],
                '_activity_id' => $a->id,
            ];
        });
    }
}
