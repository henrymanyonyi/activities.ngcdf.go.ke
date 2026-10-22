<?php

namespace App\Console\Commands;

use App\Enums\ActivityStatus;
use App\Enums\ReviewOffice;
use App\Models\Activity;
use App\Models\ReportReceipt;
use App\Notifications\ActivityAlert;
use App\Services\ActivityLifecycle;
use App\Services\Alerts;
use App\Services\AppSettings;
use App\Services\MemoTracker;
use Illuminate\Console\Command;

/**
 * Daily: approved activities start on their start date (21 October 2026),
 * RP-01 (in-progress activities past their end date become Completed with a
 * report due date) and RP-03
 * (overdue reports), and memos pending review or decision beyond the
 * reminder period in Settings. Alerts go only to authorised users, in the app.
 */
class DailyActivityChecks extends Command
{
    protected $signature = 'activities:daily';

    protected $description = 'Start and complete activities by their dates and raise overdue-report alerts';

    public function handle(ActivityLifecycle $lifecycle, Alerts $alerts, MemoTracker $memos, AppSettings $settings): int
    {
        // Approved activities start on their start date, then any whose end date has passed complete.
        $started = $lifecycle->startDueActivities();
        $completed = $lifecycle->completeEndedActivities();

        // Reports overdue and not in (none, or returned for correction). RP-03: the Office of the CEO follows it up;
        // the Head of Department sees "Overdue" on the memo page but gets no reminder.
        $overdue = Activity::query()->status(ActivityStatus::Completed)->whereDate('report_due_on', '<', today())
            ->whereDoesntHave('report', fn ($r) => $r->where('status', '!=', ReportReceipt::STATUS_RETURNED))->get();
        $overdue->each(fn (Activity $a) => $alerts->toStaffOfCeo(ActivityAlert::reportOverdue($a)));

        $pending = Activity::query()->status(ActivityStatus::UnderReview, ActivityStatus::AwaitingDecision)->get();
        $since = $memos->lastMoves($pending->pluck('id'));
        $days = $settings->int('memo_review_reminder_working_days');
        $late = $pending->filter(fn (Activity $a) => isset($since[$a->id]) && $memos->isTooLong($since[$a->id]));
        $late->each(fn (Activity $a) => $a->status === ActivityStatus::AwaitingDecision
            ? $alerts->toCeo(ActivityAlert::pendingTooLong($a, $days))
            : $alerts->toOffice($a->review_office ?? ReviewOffice::AssistantChiefOfStaff, ActivityAlert::pendingTooLong($a, $days)));

        $this->info("{$started} started, {$completed} completed, {$overdue->count()} reports overdue, {$late->count()} memos pending too long.");

        return self::SUCCESS;
    }
}
