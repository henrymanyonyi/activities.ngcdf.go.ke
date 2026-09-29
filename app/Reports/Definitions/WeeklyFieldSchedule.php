<?php

namespace App\Reports\Definitions;

use App\Enums\ActivityStatus;
use App\Enums\ParticipationStatus;
use App\Models\ActivityParticipant;
use App\Reports\Report;
use App\Support\Ui;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class WeeklyFieldSchedule extends Report
{
    public function key(): string
    {
        return 'weekly-field-schedule';
    }

    public function title(): string
    {
        return 'Weekly Field Schedule';
    }

    public function description(): string
    {
        return 'Activities and officers in the field for the coming week.';
    }

    public function icon(): string
    {
        return 'fa-calendar-week';
    }

    public function filters(): array
    {
        return ['week'];
    }

    public function subtitle(array $filters): string
    {
        [$start, $end] = $this->week($filters);

        return 'Week of '.Ui::dateRange($start, $end);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function week(array $filters): array
    {
        $start = ! empty($filters['week']) ? Carbon::parse($filters['week'])->startOfWeek() : today()->addWeek()->startOfWeek();

        return [$start, $start->copy()->endOfWeek()];
    }

    public function columns(): array
    {
        return [
            'officer' => ['Officer', 'text'],
            'department' => ['Department', 'text'],
            'reference' => ['Reference', 'text'],
            'activity' => ['Activity', 'text'],
            'location' => ['Location', 'text'],
            'start' => ['Leaves', 'date'],
            'end' => ['Returns', 'date'],
            'role' => ['Role', 'text'],
            'status' => ['Status', 'text'],
        ];
    }

    public function rows(array $filters): Collection
    {
        [$start, $end] = $this->week($filters);

        return ActivityParticipant::query()
            ->whereIn('status', ParticipationStatus::values(ParticipationStatus::Nominated, ParticipationStatus::Confirmed, ParticipationStatus::Attended))
            ->whereHas('activity', fn (Builder $q) => $q->status(ActivityStatus::Approved, ActivityStatus::InProgress, ActivityStatus::AwaitingDecision)->overlapping($start, $end))
            ->with(['staff.department', 'activity.locations.county', 'activity.locations.constituency', 'activity.locations.region'])
            ->get()
            ->sortBy(fn ($p) => $p->activity->start_date->format('Ymd').$p->displayName())
            ->map(fn (ActivityParticipant $p) => [
                'officer' => $p->displayName().($p->is_external ? ' (external)' : ''),
                'department' => $p->is_external ? $p->external_organisation : $p->staff?->department?->name,
                'reference' => $p->activity->reference,
                'activity' => $p->activity->title,
                'location' => $p->activity->locationSummary(),
                'start' => $p->activity->start_date,
                'end' => $p->activity->end_date,
                'role' => $p->role->label(),
                'status' => $p->activity->status->label(),
                '_activity_id' => $p->activity_id,
            ])->values();
    }
}
