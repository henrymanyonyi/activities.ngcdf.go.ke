<?php

namespace App\Livewire;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Directive;
use App\Services\Analytics;
use App\Support\FinancialYear;
use App\Support\Money;
use App\Support\Period;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** FRD DB-01: the CEO dashboard for a selected period. Every figure drills down (DB-07). */
#[Layout('layouts.admin')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    #[Url(except: '')]
    public string $fy = '';

    #[Url(except: '')]
    public string $quarter = '';

    public function render(Analytics $analytics): View
    {
        $filters = ['fy' => $this->fy ?: FinancialYear::current()->label(), 'quarter' => $this->quarter];
        $period = Period::fromFilters($filters);
        $activities = $analytics->activities($period);

        $byStatus = $activities->groupBy(fn (Activity $a) => $a->status->value)->map->count();
        $count = fn (ActivityStatus ...$s) => collect($s)->sum(fn ($x) => $byStatus[$x->value] ?? 0);

        // Delivery rate: of approved activities due to have ended by today, the share delivered.
        $due = $activities->filter(fn (Activity $a) => $a->end_date->lt(today()) && ($a->status->isApprovedOrLater() || $a->status === ActivityStatus::Cancelled));
        $delivered = $due->filter(fn (Activity $a) => $a->status->isDelivered())->count();

        $countable = $activities->filter(fn (Activity $a) => in_array($a->status, Analytics::spendStatuses(), true));
        $planned = $countable->sum(fn (Activity $a) => Money::toCents($a->estimated_total));
        $actual = $countable->sum(fn (Activity $a) => Money::toCents($a->actual_total));

        $link = fn (array $q = []) => route('activities.index', array_filter($q + ['fy' => $filters['fy'], 'quarter' => $this->quarter]));

        return view('livewire.dashboard', [
            'period' => $period,
            'filters' => $filters,
            'years' => FinancialYear::options(),
            'awaiting' => Activity::query()->status(ActivityStatus::AwaitingDecision)->count(),
            'queue' => Activity::query()->status(ActivityStatus::AwaitingDecision)->with('organisingDepartment')->orderBy('start_date')->limit(5)->get(),
            'counts' => [
                'planned' => $count(ActivityStatus::Draft, ActivityStatus::AwaitingDecision, ActivityStatus::Returned),
                'approved' => $count(ActivityStatus::Approved),
                'in_progress' => $count(ActivityStatus::InProgress),
                'completed' => $count(ActivityStatus::Completed, ActivityStatus::ReportReceived, ActivityStatus::Closed),
                'postponed' => $count(ActivityStatus::Postponed),
                'cancelled' => $count(ActivityStatus::Cancelled, ActivityStatus::Declined),
            ],
            'deliveryRate' => $due->count() > 0 ? intdiv($delivered * 100, $due->count()) : null,
            'dueCount' => $due->count(),
            'inField' => $analytics->inTheFieldToday()->unique('staff_id')->count(),
            'lateNotice' => $activities->where('is_late_notice', true)->count(),
            'retrospective' => $activities->where('is_retrospective', true)->count(),
            'overdueReports' => Activity::query()->status(ActivityStatus::Completed)->whereDate('report_due_on', '<', today())->count(),
            'commencementDue' => Activity::query()->status(ActivityStatus::Approved)->whereDate('start_date', '<=', today())->with('organisingDepartment')->orderBy('start_date')->get(),
            'upcoming' => Activity::query()->status(ActivityStatus::Approved)->whereBetween('start_date', [today()->addDay(), today()->addDays(14)])->with('organisingDepartment')->orderBy('start_date')->limit(6)->get(),
            'overdueDirectives' => Directive::query()->overdue()->with(['activity', 'department'])->orderBy('due_on')->limit(5)->get(),
            'planned' => $planned,
            'actual' => $actual,
            'topDepartments' => $analytics->departmentCosts($period)->take(6),
            'link' => $link,
        ]);
    }
}
