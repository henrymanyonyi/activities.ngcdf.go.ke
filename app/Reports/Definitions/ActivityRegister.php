<?php

namespace App\Reports\Definitions;

use App\Models\Activity;
use App\Queries\ActivityRegisterQuery;
use App\Reports\Report;
use App\Support\Money;
use Illuminate\Support\Collection;

/** SR-03: the register export — exactly the records matching the register's active filters. */
class ActivityRegister extends Report
{
    public function key(): string
    {
        return 'activity-register';
    }

    public function title(): string
    {
        return 'Activity Register';
    }

    public function description(): string
    {
        return 'The activity register, filtered as on screen.';
    }

    public function icon(): string
    {
        return 'fa-table-list';
    }

    public function filters(): array
    {
        return ['register'];
    }

    public function subtitle(array $filters): string
    {
        $active = collect($filters)->only(ActivityRegisterQuery::FILTERS)->filter()->count();

        return $active ? "{$active} filter(s) applied" : 'All activities';
    }

    public function columns(): array
    {
        return [
            'reference' => ['Reference', 'text'],
            'title' => ['Activity', 'text'],
            'department' => ['Requesting department', 'text'],
            'type' => ['Type', 'text'],
            'location' => ['Location', 'text'],
            'start' => ['Start', 'date'],
            'end' => ['End', 'date'],
            'days' => ['Days', 'int'],
            'staff' => ['Officers', 'int'],
            'status' => ['Status', 'text'],
            'planned' => ['Planned', 'money'],
            'actual' => ['Actual', 'money'],
        ];
    }

    public function rows(array $filters): Collection
    {
        return ActivityRegisterQuery::build($filters, $filters['sort'] ?? 'start_date', $filters['direction'] ?? 'desc')
            ->get()
            ->map(fn (Activity $a) => [
                'reference' => $a->reference,
                'title' => $a->title,
                'department' => $a->organisingDepartment?->name,
                'type' => $a->type?->name,
                'location' => $a->locationSummary(),
                'start' => $a->start_date,
                'end' => $a->end_date,
                'days' => $a->days,
                'staff' => $a->staff_count,
                'status' => $a->status->label(),
                'planned' => Money::toCents($a->estimated_total),
                'actual' => Money::toCents($a->actual_total),
                '_activity_id' => $a->id,
            ]);
    }
}
