<?php

namespace App\Reports\Definitions;

use App\Reports\Report;
use App\Services\Analytics;
use App\Support\Period;
use Illuminate\Support\Collection;

class OfficerFieldDays extends Report
{
    public function key(): string
    {
        return 'officer-field-days';
    }

    public function title(): string
    {
        return 'Officer Field Days';
    }

    public function description(): string
    {
        return 'Field days and costs per officer for a period, against the threshold.';
    }

    public function icon(): string
    {
        return 'fa-user-clock';
    }

    public function filters(): array
    {
        return ['period', 'department'];
    }

    public function columns(): array
    {
        return [
            'officer' => ['Officer', 'text'],
            'staff_number' => ['PF number', 'text'],
            'department' => ['Department', 'text'],
            'activities' => ['Activities', 'int'],
            'attended' => ['Attended', 'int'],
            'missed' => ['Did not attend', 'int'],
            'planned_days' => ['Planned days', 'int'],
            'actual_days' => ['Actual days', 'int'],
            'year_days' => ['FY days / limit', 'text'],
            'dsa' => ['DSA', 'money'],
            'travel' => ['Travel', 'money'],
            'total' => ['Total incl. share', 'money'],
        ];
    }

    public function rows(array $filters): Collection
    {
        return app(Analytics::class)->staffParticipation(Period::fromFilters($filters), $filters)->map(fn ($r) => [
            'officer' => $r['staff']?->name,
            'staff_number' => $r['staff']?->staff_number,
            'department' => $r['staff']?->department?->name,
            'activities' => $r['count'],
            'attended' => $r['attended'],
            'missed' => $r['missed'],
            'planned_days' => $r['planned_days'],
            'actual_days' => $r['actual_days'],
            'year_days' => $r['year_days'].' / '.$r['year_limit'].($r['year_days'] > $r['year_limit'] ? ' (over)' : ''),
            'dsa' => $r['dsa'],
            'travel' => $r['travel'],
            'total' => $r['total'],
        ]);
    }
}
