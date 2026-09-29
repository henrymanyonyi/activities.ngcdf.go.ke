<?php

namespace App\Reports\Definitions;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Directive;
use App\Reports\Report;
use Illuminate\Support\Collection;

class OverdueReportsAndDirectives extends Report
{
    public function key(): string
    {
        return 'overdue';
    }

    public function title(): string
    {
        return 'Overdue Reports and Directives';
    }

    public function description(): string
    {
        return 'Activities with back-to-office reports not received, and directives outstanding.';
    }

    public function icon(): string
    {
        return 'fa-triangle-exclamation';
    }

    public function filters(): array
    {
        return [];
    }

    public function subtitle(array $filters): string
    {
        return 'As at '.today()->format('d M Y');
    }

    public function columns(): array
    {
        return [
            'kind' => ['Item', 'text'],
            'reference' => ['Activity', 'text'],
            'title' => ['Detail', 'text'],
            'department' => ['Department', 'text'],
            'due' => ['Due', 'date'],
            'days_overdue' => ['Days overdue', 'int'],
        ];
    }

    public function rows(array $filters): Collection
    {
        $reports = Activity::query()->status(ActivityStatus::Completed)->whereDate('report_due_on', '<', today())
            ->with('organisingDepartment')->get()
            ->map(fn (Activity $a) => [
                'kind' => 'Back-to-office report',
                'reference' => $a->reference,
                'title' => $a->title,
                'department' => $a->organisingDepartment?->name,
                'due' => $a->report_due_on,
                'days_overdue' => (int) $a->report_due_on->diffInDays(today()),
                '_activity_id' => $a->id,
            ]);

        $directives = Directive::query()->open()->whereNotNull('due_on')->whereDate('due_on', '<', today())
            ->with(['activity', 'department'])->get()
            ->map(fn (Directive $d) => [
                'kind' => 'Directive',
                'reference' => $d->activity?->reference,
                'title' => $d->body,
                'department' => $d->department?->name,
                'due' => $d->due_on,
                'days_overdue' => (int) $d->due_on->diffInDays(today()),
                '_activity_id' => $d->activity_id,
            ]);

        return $reports->concat($directives)->sortByDesc('days_overdue')->values();
    }
}
