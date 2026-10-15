<?php

namespace App\Console\Commands;

use App\Enums\ActivityStatus;
use App\Enums\ReviewOffice;
use App\Models\Activity;
use App\Notifications\ActivityAlert;
use App\Services\ActivityLifecycle;
use App\Services\Alerts;
use App\Services\AppSettings;
use App\Services\MemoTracker;
use Illuminate\Console\Command;

/**
 * Daily: RP-01 (in-progress activities past their end date become Completed
 * with a report due date), EX-01 (commencement confirmations due) and RP-03
 * (overdue reports), and memos pending review or decision beyond the
 * reminder period in Settings. Alerts go only to authorised users, in the app.
 */
class DailyActivityChecks extends Command
{
    protected $signature = 'activities:daily';

    protected $description = 'Complete ended activities and raise commencement and overdue-report alerts';

    public function handle(ActivityLifecycle $lifecycle, Alerts $alerts, MemoTracker $memos, AppSettings $settings): int
    {
        $completed = $lifecycle->completeEndedActivities();

        $due = Activity::query()->status(ActivityStatus::Approved)->whereDate('start_date', '<=', today())->get();
        $due->each(fn (Activity $a) => $alerts->toStaffOfCeo(ActivityAlert::commencementDue($a)));

        $overdue = Activity::query()->status(ActivityStatus::Completed)->whereDate('report_due_on', '<', today())->get();
        $overdue->each(fn (Activity $a) => $alerts->toStaffOfCeo(ActivityAlert::reportOverdue($a)));

        $pending = Activity::query()->status(ActivityStatus::UnderReview, ActivityStatus::AwaitingDecision)->get();
        $since = $memos->lastMoves($pending->pluck('id'));
        $days = $settings->int('memo_review_reminder_working_days');
        $late = $pending->filter(fn (Activity $a) => isset($since[$a->id]) && $memos->isTooLong($since[$a->id]));
        $late->each(fn (Activity $a) => $a->status === ActivityStatus::AwaitingDecision
            ? $alerts->toCeo(ActivityAlert::pendingTooLong($a, $days))
            : $alerts->toOffice($a->review_office ?? ReviewOffice::AssistantChiefOfStaff, ActivityAlert::pendingTooLong($a, $days)));

        $this->info("{$completed} completed, {$due->count()} commencement confirmations due, {$overdue->count()} reports overdue, {$late->count()} memos pending too long.");

        return self::SUCCESS;
    }
}
