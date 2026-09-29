<?php

namespace App\Livewire;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Department;
use App\Models\Region;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** FRD PL-06: month and week views of all activities, filterable. PL-07: same place, overlapping dates, different departments. */
#[Layout('layouts.admin')]
#[Title('Calendar')]
class Calendar extends Component
{
    #[Url(except: 'month')]
    public string $view = 'month';

    #[Url(except: '')]
    public string $date = '';

    #[Url(except: '')]
    public string $department_id = '';

    #[Url(except: '')]
    public string $region_id = '';

    #[Url(except: '')]
    public string $activity_type_id = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->date = $this->date ?: today()->toDateString();
    }

    public function move(int $step): void
    {
        $date = Carbon::parse($this->date);
        $this->date = ($this->view === 'week' ? $date->addWeeks($step) : $date->addMonthsNoOverflow($step))->toDateString();
    }

    public function showWeek(string $date): void
    {
        $this->view = 'week';
        $this->date = Carbon::parse($date)->toDateString();
    }

    public function today(): void
    {
        $this->date = today()->toDateString();
    }

    public function render(): View
    {
        $anchor = Carbon::parse($this->date);
        [$from, $to] = $this->view === 'week'
            ? [$anchor->copy()->startOfWeek(), $anchor->copy()->endOfWeek()]
            : [$anchor->copy()->startOfMonth()->startOfWeek(), $anchor->copy()->endOfMonth()->endOfWeek()];

        $activities = Activity::query()
            ->overlapping($from, $to)
            ->when($this->status, fn (Builder $q, $s) => $q->where('status', $s), fn (Builder $q) => $q->whereNotIn('status', ActivityStatus::values(ActivityStatus::Declined, ActivityStatus::Cancelled)))
            ->when($this->department_id, fn (Builder $q, $id) => $q->where('organising_department_id', $id))
            ->when($this->activity_type_id, fn (Builder $q, $id) => $q->where('activity_type_id', $id))
            ->when($this->region_id, fn (Builder $q, $id) => $q->whereHas('locations', fn (Builder $l) => $l->where('region_id', $id)))
            ->with(['organisingDepartment', 'locations.county'])
            ->orderBy('start_date')
            ->get();

        // PL-07: different departments, same county, overlapping dates.
        $joint = [];
        foreach ($activities as $i => $a) {
            foreach ($activities->slice($i + 1) as $b) {
                if ($a->organising_department_id !== $b->organising_department_id
                    && $a->start_date->lte($b->end_date) && $b->start_date->lte($a->end_date)
                    && $a->locations->pluck('county_id')->filter()->intersect($b->locations->pluck('county_id')->filter())->isNotEmpty()) {
                    $joint[$a->id][] = $b->reference;
                    $joint[$b->id][] = $a->reference;
                }
            }
        }

        $days = collect();
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $days->push($d->copy());
        }

        return view('livewire.calendar', [
            'days' => $days,
            'anchor' => $anchor,
            'activities' => $activities,
            'joint' => $joint,
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'regions' => Region::query()->orderBy('name')->get(['id', 'name']),
            'types' => ActivityType::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => ActivityStatus::cases(),
        ]);
    }
}
