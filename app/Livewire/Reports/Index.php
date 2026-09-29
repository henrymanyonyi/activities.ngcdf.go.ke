<?php

namespace App\Livewire\Reports;

use App\Reports\Report;
use App\Reports\ReportCatalog;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** FRD Section 10: the standard reports. */
#[Layout('layouts.admin')]
#[Title('Reports')]
class Index extends Component
{
    public function render(): View
    {
        return view('livewire.reports.index', [
            'reports' => ReportCatalog::all()->filter(fn (Report $r) => Auth::user()->can($r->permission())),
        ]);
    }
}
