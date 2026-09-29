<?php

namespace App\Livewire\Reports;

use App\Models\Department;
use App\Reports\Report;
use App\Reports\ReportCatalog;
use App\Services\AccessLogger;
use App\Support\FinancialYear;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** One standard report on screen, with filtered Excel / PDF / print export for permitted users (SR-03). */
#[Layout('layouts.admin')]
class Show extends Component
{
    public string $report;

    #[Url(except: '')]
    public string $fy = '';

    #[Url(except: '')]
    public string $quarter = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $department_id = '';

    #[Url(except: '')]
    public string $week = '';

    public function mount(string $report, AccessLogger $log): void
    {
        $definition = ReportCatalog::find($report);
        abort_unless($definition && Auth::user()->can($definition->permission()), 404);
        $this->report = $report;
        $log->log(AccessLogger::VIEW, meta: ['report' => $report]);
    }

    /** @return array<string, string> */
    public function filters(): array
    {
        return ['fy' => $this->fy, 'quarter' => $this->quarter, 'from' => $this->from, 'to' => $this->to, 'department_id' => $this->department_id, 'week' => $this->week];
    }

    public function render(): View
    {
        /** @var Report $definition */
        $definition = ReportCatalog::find($this->report);
        $filters = $this->filters();
        $rows = $definition->rows($filters);

        return view('livewire.reports.show', [
            'definition' => $definition,
            'rows' => $rows,
            'totals' => $definition->totals($rows),
            'exportQuery' => array_filter($filters),
            'years' => FinancialYear::options(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
        ])->title($definition->title());
    }
}
