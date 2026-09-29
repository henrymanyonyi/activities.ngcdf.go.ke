<?php

namespace App\Livewire\Activities;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\County;
use App\Models\Department;
use App\Models\Region;
use App\Models\Staff;
use App\Queries\ActivityRegisterQuery;
use App\Support\FinancialYear;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** FRD SR-01..SR-04: the activity register. Filters live in the URL so dashboards can drill down (DB-07). */
#[Layout('layouts.admin')]
#[Title('Activity register')]
class Register extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $department_id = '';

    #[Url(except: '')]
    public string $staff_id = '';

    #[Url(except: '')]
    public string $region_id = '';

    #[Url(except: '')]
    public string $county_id = '';

    #[Url(except: '')]
    public string $activity_type_id = '';

    #[Url(except: '')]
    public string $fy = '';

    #[Url(except: '')]
    public string $quarter = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $flag = '';

    #[Url(except: 'start_date')]
    public string $sort = 'start_date';

    #[Url(except: 'desc')]
    public string $direction = 'desc';

    public bool $showMoreFilters = false;

    public function updated(string $property): void
    {
        if (in_array($property, ActivityRegisterQuery::FILTERS, true)) {
            $this->resetPage();
        }
    }

    public function sortBy(string $column): void
    {
        if (! array_key_exists($column, ActivityRegisterQuery::SORTS)) {
            return;
        }

        $this->direction = $this->sort === $column && $this->direction === 'desc' ? 'asc' : 'desc';
        $this->sort = $column;
    }

    public function clearFilters(): void
    {
        $this->reset(ActivityRegisterQuery::FILTERS);
        $this->resetPage();
    }

    /** @return array<string, string> */
    public function filters(): array
    {
        return collect(ActivityRegisterQuery::FILTERS)->mapWithKeys(fn ($f) => [$f => $this->{$f}])->all();
    }

    public function hasFilters(): bool
    {
        return collect($this->filters())->filter()->isNotEmpty();
    }

    public function render(): View
    {
        $activities = ActivityRegisterQuery::build($this->filters(), $this->sort, $this->direction)->paginate(25);

        return view('livewire.activities.register', [
            'activities' => $activities,
            'total' => Activity::query()->count(),
            'statuses' => ActivityStatus::cases(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'types' => ActivityType::query()->orderBy('name')->get(['id', 'name']),
            'regions' => Region::query()->orderBy('name')->get(['id', 'name']),
            'counties' => County::query()->orderBy('name')->get(['id', 'name']),
            'staff' => $this->staff_id ? Staff::query()->whereKey($this->staff_id)->get(['id', 'name']) : collect(),
            'officers' => Staff::query()->active()->orderBy('name')->get(['id', 'name', 'staff_number']),
            'years' => FinancialYear::options(),
            'flags' => ActivityRegisterQuery::FLAGS,
            'exportQuery' => array_filter($this->filters() + ['sort' => $this->sort, 'direction' => $this->direction]),
        ]);
    }
}
