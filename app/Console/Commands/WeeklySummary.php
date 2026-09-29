<?php

namespace App\Console\Commands;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityParticipant;
use App\Notifications\ActivityAlert;
use App\Services\Alerts;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * FRD NT-02: Monday summary for the CEO, in the app. It carries counts only
 * and links to the Weekly Field Schedule, which holds the detail.
 */
class WeeklySummary extends Command
{
    protected $signature = 'activities:weekly-summary';

    protected $description = 'Post the CEO\'s Monday summary alert';

    public function handle(Alerts $alerts): int
    {
        $start = today()->startOfWeek();
        $end = today()->endOfWeek();

        $starting = Activity::query()->status(ActivityStatus::Approved, ActivityStatus::InProgress)->whereBetween('start_date', [$start, $end])->count();
        $officers = ActivityParticipant::query()->where('is_external', false)
            ->whereHas('activity', fn (Builder $q) => $q->status(ActivityStatus::Approved, ActivityStatus::InProgress)->overlapping($start, $end))
            ->distinct('staff_id')->count('staff_id');
        $awaiting = Activity::query()->status(ActivityStatus::AwaitingDecision)->count();
        $overdue = Activity::query()->status(ActivityStatus::Completed)->whereDate('report_due_on', '<', today())->count();

        $alerts->toCeo(new ActivityAlert(
            'weekly_summary',
            "This week: {$starting} activities starting, {$officers} officers expected in the field, {$awaiting} awaiting your decision, {$overdue} reports overdue.",
            null,
            'fa-calendar-week',
        ));

        $this->info('Weekly summary posted.');

        return self::SUCCESS;
    }
}
