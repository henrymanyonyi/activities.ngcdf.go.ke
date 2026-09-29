<?php

namespace App\Livewire;

use App\Models\Department;
use App\Services\Analytics;
use App\Support\FinancialYear;
use App\Support\Period;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * FRD DB-02 (the concept's Staff Summary): headline cards and one expandable
 * row per officer, with planned against actual days and cumulative field days
 * against the limit. Staff with no participation are listed too, as plain
 * data, without any "fairness" score.
 */
#[Layout('layouts.admin')]
#[Title('Staff participation')]
class Participation extends Component
{
    #[Url(except: '')]
    public string $fy = '';

    #[Url(except: '')]
    public string $quarter = '';

    #[Url(except: '')]
    public string $department_id = '';

    #[Url(except: '')]
    public string $search = '';

    public ?int $expanded = null;

    public bool $showNone = false;

    public function toggle(int $staffId): void
    {
        $this->expanded = $this->expanded === $staffId ? null : $staffId;
    }

    public function render(Analytics $analytics): View
    {
        $filters = ['fy' => $this->fy ?: FinancialYear::current()->label(), 'quarter' => $this->quarter, 'department_id' => $this->department_id, 'search' => $this->search];
        $period = Period::fromFilters($filters);
        $rows = $analytics->staffParticipation($period, $filters);
        $none = $analytics->nonParticipants($rows->pluck('staff.id')->all(), $filters);

        return view('livewire.participation', [
            'period' => $period,
            'rows' => $rows,
            'totals' => [
                'staff' => $rows->count(),
                'activities' => $rows->flatMap(fn ($r) => $r['activities']->pluck('activity.id'))->unique()->count(),
                'days' => $rows->sum('planned_days'),
                'dsa' => $rows->sum('dsa'),
                'travel' => $rows->sum('travel'),
            ],
            'none' => $this->showNone ? $none : null,
            'noneCount' => $none->count(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'years' => FinancialYear::options(),
        ]);
    }
}
