<?php

namespace App\Livewire;

use App\Services\Analytics;
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
 * with budget and variance where recorded.
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

    public function render(Analytics $analytics): View
    {
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
