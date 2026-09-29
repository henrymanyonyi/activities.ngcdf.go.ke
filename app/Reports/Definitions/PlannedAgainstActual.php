<?php

namespace App\Reports\Definitions;

use App\Reports\Report;
use App\Services\Analytics;
use App\Support\Period;
use Illuminate\Support\Collection;

class PlannedAgainstActual extends Report
{
    public function key(): string
    {
        return 'planned-against-actual';
    }

    public function title(): string
    {
        return 'Planned against Actual';
    }

    public function description(): string
    {
        return 'Activities, days and cost planned against delivered, with variance, by department and quarter.';
    }

    public function icon(): string
    {
        return 'fa-scale-balanced';
    }

    public function columns(): array
    {
        return [
            'department' => ['Department', 'text'],
            'quarter' => ['Quarter', 'text'],
            'planned' => ['Planned', 'int'],
            'delivered' => ['Delivered', 'int'],
            'postponed' => ['Postponed', 'int'],
            'cancelled' => ['Cancelled', 'int'],
            'delivery_rate' => ['Delivery rate', 'percent'],
            'planned_days' => ['Planned days', 'int'],
            'actual_days' => ['Actual days', 'int'],
            'planned_cost' => ['Planned cost', 'money'],
            'actual_cost' => ['Actual cost', 'money'],
            'variance' => ['Variance', 'money'],
        ];
    }

    public function rows(array $filters): Collection
    {
        return app(Analytics::class)->plannedVsActual(Period::fromFilters($filters));
    }
}
