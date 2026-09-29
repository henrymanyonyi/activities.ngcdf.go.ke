<?php

namespace App\Reports\Definitions;

use App\Reports\Report;
use App\Services\Analytics;
use App\Support\Period;
use Illuminate\Support\Collection;

class DepartmentFieldExpenditure extends Report
{
    public function key(): string
    {
        return 'department-field-expenditure';
    }

    public function title(): string
    {
        return 'Department Field Expenditure';
    }

    public function description(): string
    {
        return 'DSA, travel and other costs per requesting department, against budget where recorded.';
    }

    public function icon(): string
    {
        return 'fa-building-columns';
    }

    public function columns(): array
    {
        return [
            'department' => ['Department', 'text'],
            'staff_count' => ['Officers', 'int'],
            'activity_count' => ['Activities', 'int'],
            'days' => ['Days', 'int'],
            'dsa' => ['DSA', 'money'],
            'travel' => ['Travel', 'money'],
            'other' => ['Other', 'money'],
            'total' => ['Total', 'money'],
            'budget' => ['Budget', 'money'],
            'variance' => ['Budget remaining', 'money'],
        ];
    }

    public function rows(array $filters): Collection
    {
        return app(Analytics::class)->departmentCosts(Period::fromFilters($filters), 'requesting')
            ->map(fn ($r) => ['department' => $r['department']?->name ?? 'Unassigned'] + $r);
    }
}
