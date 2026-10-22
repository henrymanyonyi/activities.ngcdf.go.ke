<?php

namespace App\Reports\Definitions;

use App\Reports\Report;
use App\Services\BudgetPosition;
use App\Support\FinancialYear;
use Illuminate\Support\Collection;

/**
 * Each department's budget position for a financial year: allocation,
 * pending requests, approved, adjustments, committed, spent, balance and
 * utilisation (definitions in App\Services\BudgetPosition).
 */
class BudgetPositionReport extends Report
{
    public function __construct(private BudgetPosition $budget) {}

    public function key(): string
    {
        return 'budget-position';
    }

    public function title(): string
    {
        return 'Departmental Budget Position';
    }

    public function description(): string
    {
        return 'Allocation, pending requests, approved, committed and spent amounts, and the balance per department for the year.';
    }

    public function icon(): string
    {
        return 'fa-scale-balanced';
    }

    public function filters(): array
    {
        return ['fy', 'department'];
    }

    public function subtitle(array $filters): string
    {
        return 'FY '.$this->year($filters)->label().' · balance = allocation − spent − committed; pending not deducted';
    }

    public function columns(): array
    {
        return [
            'department' => ['Department', 'text'],
            'allocation' => ['Allocation', 'money'],
            'pending_count' => ['Pending memos', 'int'],
            'pending' => ['Pending requests', 'money'],
            'approved_count' => ['Approved activities', 'int'],
            'approved' => ['Approved', 'money'],
            'adjustments' => ['Adjustments since approval', 'money'],
            'released' => ['Released by cancellations', 'money'],
            'committed' => ['Committed', 'money'],
            'spent' => ['Spent (Finance returns)', 'money'],
            'balance' => ['Balance', 'money'],
            'utilisation' => ['Utilisation', 'percent'],
            'spent_percent' => ['Spent %', 'percent'],
        ];
    }

    public function rows(array $filters): Collection
    {
        $departmentId = ! empty($filters['department_id']) ? (int) $filters['department_id'] : null;

        return $this->budget->forYear($this->year($filters), $departmentId)
            ->map(fn (array $r) => ['department' => $r['department']?->name ?? 'No department'] + $r);
    }

    private function year(array $filters): FinancialYear
    {
        return ! empty($filters['fy']) ? FinancialYear::fromLabel($filters['fy']) : FinancialYear::current();
    }
}
