<?php

namespace App\Reports\Definitions;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Reports\Report;
use App\Services\Analytics;
use App\Support\Money;
use App\Support\Period;
use Illuminate\Support\Collection;

class FieldActivityPlan extends Report
{
    public function key(): string
    {
        return 'field-activity-plan';
    }

    public function title(): string
    {
        return 'Field Activity Plan';
    }

    public function description(): string
    {
        return 'All planned and approved activities for a period, by department and location.';
    }

    public function icon(): string
    {
        return 'fa-map-location-dot';
    }

    public function filters(): array
    {
        return ['period', 'department'];
    }

    public function columns(): array
    {
        return [
            'department' => ['Department', 'text'],
            'reference' => ['Reference', 'text'],
            'title' => ['Activity', 'text'],
            'location' => ['Location', 'text'],
            'start' => ['Start', 'date'],
            'end' => ['End', 'date'],
            'days' => ['Days', 'int'],
            'staff' => ['Officers', 'int'],
            'status' => ['Status', 'text'],
            'planned' => ['Planned cost', 'money'],
        ];
    }

    public function rows(array $filters): Collection
    {
        return app(Analytics::class)
            ->activities(Period::fromFilters($filters), $filters, [ActivityStatus::Draft, ActivityStatus::AwaitingDecision, ActivityStatus::Returned, ActivityStatus::Approved, ActivityStatus::InProgress, ActivityStatus::Postponed])
            ->sortBy(fn (Activity $a) => ($a->organisingDepartment?->name ?? 'zz').$a->start_date->format('Ymd'))
            ->map(fn (Activity $a) => [
                'department' => $a->organisingDepartment?->name ?? 'Unassigned',
                'reference' => $a->reference,
                'title' => $a->title,
                'location' => $a->locationSummary(),
                'start' => $a->start_date,
                'end' => $a->end_date,
                'days' => $a->days,
                'staff' => $a->staff_count,
                'status' => $a->status->label(),
                'planned' => Money::toCents($a->estimated_total),
                '_activity_id' => $a->id,
            ])->values();
    }
}
