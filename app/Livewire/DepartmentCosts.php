<?php

namespace App\Livewire;

use App\Models\Department;
use App\Services\Analytics;
use App\Services\BudgetPosition;
use App\Support\FinancialYear;
use App\Support\Period;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * FRD DB-03 (the concept's Department Costs): Board-wide DSA, travel and
 * grand totals, and per department the staff, activities, days and costs,
 * with budget and balance where recorded. The Budget position tab shows each
 * department's allocation, pending requests, approved, committed and spent
 * amounts and balance for the year (App\Services\BudgetPosition), and for
 * one department what lies behind them.
 */
#[Layout('layouts.admin')]
#[Title('Department costs')]
class DepartmentCosts extends Component
{
    #[Url(except: '')]
    public string $fy = '';

    #[Url(except: '')]
    public string $quarter = '';

    #[Url(except: 'requesting')]
    public string $lens = 'requesting';

    /** Budget position: one department's detail. */
    #[Url(except: '')]
    public string $department = '';

    public function render(Analytics $analytics, BudgetPosition $budget): View
    {
        if ($this->lens === 'budget') {
            $fy = FinancialYear::fromLabel($this->fy ?: FinancialYear::current()->label());
            $rows = $budget->forYear($fy);
            $selected = $this->department !== '' ? Department::query()->find($this->department) : null;

            return view('livewire.department-costs', [
                'fyLabel' => $fy->label(),
                'rows' => $rows,
                'totals' => $budget->totals($rows),
                'selected' => $selected,
                'selectedRow' => $selected ? $rows->first(fn ($r) => $r['department']?->id === $selected->id) : null,
                'detail' => $selected ? $budget->detail($fy, $selected->id) : null,
                'years' => FinancialYear::options(),
                'filters' => ['fy' => $fy->label()],
            ]);
        }

        $filters = ['fy' => $this->fy ?: FinancialYear::current()->label(), 'quarter' => $this->quarter];
        $period = Period::fromFilters($filters);
        $rows = $analytics->departmentCosts($period, $this->lens === 'officer' ? 'officer' : 'requesting');

        return view('livewire.department-costs', [
            'period' => $period,
            'rows' => $rows,
            'filters' => $filters,
            'totals' => collect(['dsa', 'travel', 'other', 'total', 'planned', 'actual', 'days'])->mapWithKeys(fn ($k) => [$k => $rows->sum($k)])->all(),
            'years' => FinancialYear::options(),
        ]);
    }
}
