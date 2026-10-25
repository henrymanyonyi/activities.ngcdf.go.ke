<?php

namespace App\Reports\Definitions;

use App\Enums\BudgetStatus;
use App\Models\BudgetAdjustment;
use App\Models\DepartmentBudget;
use App\Reports\Report;
use App\Services\Budgeting;
use App\Support\FinancialYear;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Every approved change to the year's department budgets, line by line: the
 * allocation before, the change, the allocation after, the reason, the
 * effective date and who approved it; then, per department, the budget as
 * approved, the increases and reductions, and the allocation now.
 */
class BudgetAdjustmentsRegister extends Report
{
    public function __construct(private Budgeting $budgeting) {}

    public function key(): string
    {
        return 'budget-adjustments';
    }

    public function title(): string
    {
        return 'Budget Adjustments Register';
    }

    public function description(): string
    {
        return 'Each approved increase, reduction and reallocation, with the allocation before and after, and each department\'s approved and current budget.';
    }

    public function icon(): string
    {
        return 'fa-sliders';
    }

    public function filters(): array
    {
        return ['fy', 'department'];
    }

    public function subtitle(array $filters): string
    {
        return 'FY '.$this->year($filters)->label().' · current = approved + increases − reductions';
    }

    public function columns(): array
    {
        return [
            'department' => ['Department', 'text'],
            'line' => ['Budget line', 'text'],
            'kind' => ['Change', 'text'],
            'before' => ['Allocation before', 'money'],
            'change' => ['Adjustment', 'money'],
            'after' => ['Allocation after', 'money'],
            'reason' => ['Reason', 'text'],
            'effective' => ['Effective', 'date'],
            'approved_by' => ['Approved by', 'text'],
            'approved_at' => ['Approved', 'text'],
        ];
    }

    /** Totals of before and after would add unrelated lines together; only the changes are summed. */
    public function totals(Collection $rows): array
    {
        return ['change' => (int) $rows->sum('change')];
    }

    public function rows(array $filters): Collection
    {
        $fy = $this->year($filters)->label();
        $departmentId = ! empty($filters['department_id']) ? (int) $filters['department_id'] : null;

        $changes = BudgetAdjustment::query()->where('status', BudgetStatus::Approved->value)
            ->whereHas('budget', fn (Builder $b) => $b->where('financial_year', $fy)->when($departmentId, fn ($q, $id) => $q->where('department_id', $id)))
            ->with(['budget.department', 'line', 'fromLine', 'approver'])->orderBy('approved_at')->orderBy('id')->get()
            ->flatMap(function (BudgetAdjustment $a) {
                $row = fn (?string $line, string $kind, ?string $before, ?string $after) => [
                    'department' => $a->budget->department?->name,
                    'line' => $line,
                    'kind' => $kind,
                    'before' => $before !== null ? Money::toCents($before) : null,
                    'change' => $before !== null && $after !== null ? Money::toCents($after) - Money::toCents($before) : null,
                    'after' => $after !== null ? Money::toCents($after) : null,
                    'reason' => $a->reason,
                    'effective' => $a->effective_on,
                    'approved_by' => $a->approver?->name,
                    'approved_at' => $a->approved_at?->format('d M Y H:i'),
                ];

                return array_values(array_filter([
                    $a->kind === BudgetAdjustment::KIND_REALLOCATION ? $row($a->fromLine?->label(withYear: false), 'Reallocation (from)', $a->from_line_before, $a->from_line_after) : null,
                    $row($a->line?->label(withYear: false), $a->kind === BudgetAdjustment::KIND_REALLOCATION ? 'Reallocation (to)' : $a->kindLabel(), $a->line_before, $a->line_after),
                ]));
            });

        $summaries = DepartmentBudget::query()->approved()->where('financial_year', $fy)->when($departmentId, fn ($q, $id) => $q->where('department_id', $id))
            ->with('department')->get()->sortBy('department.name')
            ->map(function (DepartmentBudget $b) {
                $t = $this->budgeting->totals($b);

                return [
                    'department' => $b->department?->name,
                    'line' => 'Whole budget: approved KES '.Money::format($t['original']).', increases KES '.Money::format($t['increases']).', reductions KES '.Money::format($t['decreases']),
                    'kind' => 'Summary',
                    'before' => $t['original'],
                    'change' => null,
                    'after' => $t['current'],
                    'reason' => null,
                    'effective' => null,
                    'approved_by' => $b->approver?->name,
                    'approved_at' => $b->approved_at?->format('d M Y H:i'),
                ];
            });

        return $changes->concat($summaries)->values();
    }

    private function year(array $filters): FinancialYear
    {
        return ! empty($filters['fy']) ? FinancialYear::fromLabel($filters['fy']) : FinancialYear::current();
    }
}
