<?php

namespace App\Console\Commands;

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Notifications\ActivityAlert;
use App\Services\ActivityLifecycle;
use App\Services\Alerts;
use Illuminate\Console\Command;

/**
 * Daily: RP-01 (in-progress activities past their end date become Completed
 * with a report due date), EX-01 (commencement confirmations due) and RP-03
 * (overdue reports). Alerts go only to the three users, in the app.
 */
class DailyActivityChecks extends Command
{
    protected $signature = 'activities:daily';

    protected $description = 'Complete ended activities and raise commencement and overdue-report alerts';

    public function handle(ActivityLifecycle $lifecycle, Alerts $alerts): int
    {
        $completed = $lifecycle->completeEndedActivities();

        $due = Activity::query()->status(ActivityStatus::Approved)->whereDate('start_date', '<=', today())->get();
        $due->each(fn (Activity $a) => $alerts->toStaffOfCeo(ActivityAlert::commencementDue($a)));

        $overdue = Activity::query()->status(ActivityStatus::Completed)->whereDate('report_due_on', '<', today())->get();
        $overdue->each(fn (Activity $a) => $alerts->toStaffOfCeo(ActivityAlert::reportOverdue($a)));

        $this->info("{$completed} completed, {$due->count()} commencement confirmations due, {$overdue->count()} reports overdue.");

        return self::SUCCESS;
    }
}
