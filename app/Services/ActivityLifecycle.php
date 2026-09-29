<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\DecisionMode;
use App\Enums\DecisionType;
use App\Enums\ParticipationStatus;
use App\Exceptions\ActivityWorkflowException;
use App\Models\Activity;
use App\Models\ActivityAmendment;
use App\Models\ActivityDocument;
use App\Models\ActivityStatusHistory;
use App\Models\CeoDecision;
use App\Models\ReportReceipt;
use App\Models\User;
use App\Notifications\ActivityAlert;
use App\Support\Money;
use App\Support\WorkingDays;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of Activity::status (FRD Section 5). Each method checks the
 * user's permission (CF-02), the transition, and the business rules, then
 * writes the status history in the same transaction.
 */
class ActivityLifecycle
{
    public function __construct(
        private ActivityCosting $costing,
        private DsaCalculator $dsa,
        private ParticipantChecks $checks,
        private AppSettings $settings,
        private DocumentStore $documents,
        private Alerts $alerts,
    ) {}

    /** Record the starting status of a newly created activity. */
    public function begin(Activity $activity, ?User $user = null, ?string $actorName = null): void
    {
        $activity->forceFill(['status' => ActivityStatus::Draft])->saveQuietly();
        $this->history($activity, null, ActivityStatus::Draft, null, $user, $actorName);
    }

    /** CD-01: Chief of Staff / Assistant submit to the CEO's decision queue. */
    public function submitForDecision(Activity $activity, User $user): void
    {
        $this->authorize($user, 'activities.submit');
        $this->assertReadyForDecision($activity);

        $this->move($activity, ActivityStatus::AwaitingDecision, null, $user);
        $this->alerts->toCeo(ActivityAlert::awaitingDecision($activity));
    }

    /** CD-02: the CEO decides in the system. A comment is mandatory to decline or return. */
    public function decide(Activity $activity, DecisionType $decision, ?string $comment, User $ceo): CeoDecision
    {
        $this->authorize($ceo, 'activities.decide');

        if ($activity->status !== ActivityStatus::AwaitingDecision) {
            throw new ActivityWorkflowException('Only an activity awaiting decision can be decided.');
        }

        return $this->applyDecision($activity, $decision, $comment, DecisionMode::InSystem, $ceo);
    }

    /**
     * CD-03: a decision the CEO gave outside the system (e.g. a signed memo),
     * recorded with the memo reference, date and a scanned copy, and labelled
     * as recorded on the CEO's behalf.
     */
    public function recordDecision(Activity $activity, DecisionType $decision, ?string $comment, string $memoReference, Carbon $memoDate, UploadedFile $scan, User $user): CeoDecision
    {
        $this->authorize($user, 'decisions.record');

        if (! in_array($activity->status, [ActivityStatus::Draft, ActivityStatus::AwaitingDecision, ActivityStatus::Returned], true)) {
            throw new ActivityWorkflowException('A decision can only be recorded for an activity that has not yet been decided.');
        }

        if (trim($memoReference) === '') {
            throw new ActivityWorkflowException('Enter the memo reference of the CEO\'s decision.');
        }

        if ($decision === DecisionType::Approved) {
            $this->assertHasTeam($activity);
        }

        return DB::transaction(function () use ($activity, $decision, $comment, $memoReference, $memoDate, $scan, $user) {
            $document = $this->documents->store($activity, $scan, ActivityDocument::KIND_DECISION, $user);

            return $this->applyDecision($activity, $decision, $comment, DecisionMode::RecordedOnBehalf, $user, trim($memoReference), $memoDate, $document->id);
        });
    }

    /** EX-01: confirm the activity has started. */
    public function confirmCommencement(Activity $activity, User $user, ?Carbon $actualStart = null): void
    {
        $this->authorize($user, 'activities.execute');
        $actualStart ??= today();

        if ($activity->start_date->isAfter(today())) {
            throw new ActivityWorkflowException('Commencement can be confirmed from the start date onwards.');
        }

        $this->move($activity, ActivityStatus::InProgress, 'Commencement confirmed', $user, extra: [
            'commenced_at' => now(),
            'commenced_by' => $user->id,
            'actual_start_date' => $actualStart,
        ]);
    }

    /**
     * EX-03: postpone. With new dates, the overlap check re-runs; a move
     * within tolerance keeps the approval, a larger move returns the activity
     * to the CEO. Without new dates, the activity waits as Postponed.
     *
     * @return list<string> warnings (conflicts found on the new dates)
     */
    public function postpone(Activity $activity, string $reason, User $user, ?Carbon $newStart = null, ?int $newDays = null): array
    {
        $this->authorize($user, 'activities.postpone');
        $this->requireReason($reason, 'Give a reason for postponing.');

        if (! in_array($activity->status, [ActivityStatus::Approved, ActivityStatus::Postponed, ActivityStatus::AwaitingDecision], true)) {
            throw new ActivityWorkflowException("An activity that is {$activity->status->label()} cannot be postponed.");
        }

        if (! $newStart) {
            $this->move($activity, ActivityStatus::Postponed, $reason, $user, extra: ['status_reason' => $reason]);

            return [];
        }

        return $this->reschedule($activity, $newStart, $newDays ?? $activity->days, $reason, $user, ActivityAmendment::KIND_DATES);
    }

    /**
     * EX-04: extend by extra days. Costs are recalculated; an extension beyond
     * tolerance returns the activity to the CEO.
     *
     * @return list<string> warnings
     */
    public function extend(Activity $activity, int $extraDays, string $reason, User $user): array
    {
        $this->authorize($user, 'activities.execute');
        $this->requireReason($reason, 'Give a reason for the extension.');

        if ($extraDays < 1) {
            throw new ActivityWorkflowException('An extension must add at least one day.');
        }

        if (! in_array($activity->status, [ActivityStatus::Approved, ActivityStatus::InProgress], true)) {
            throw new ActivityWorkflowException('Only an approved or in-progress activity can be extended.');
        }

        return $this->reschedule($activity, $activity->start_date, $activity->days + $extraDays, $reason, $user, ActivityAmendment::KIND_EXTENSION);
    }

    public function cancel(Activity $activity, string $reason, User $user): void
    {
        $this->authorize($user, 'activities.cancel');
        $this->requireReason($reason, 'Give a reason for cancelling.');
        $this->move($activity, ActivityStatus::Cancelled, $reason, $user, extra: ['status_reason' => $reason]);
    }

    /** CF-10: a Draft may be discarded; anything else is cancelled. */
    public function discardDraft(Activity $activity, User $user): void
    {
        $this->authorize($user, 'activities.manage');

        if ($activity->status !== ActivityStatus::Draft) {
            throw new ActivityWorkflowException('Only a draft can be discarded. Cancel the activity instead.');
        }

        $activity->delete();
    }

    /**
     * RP-01: the activity is complete. The report due date is set from the end date.
     * $user null means the daily schedule moved it after the end date passed.
     */
    public function complete(Activity $activity, ?User $user = null, ?Carbon $actualEnd = null): void
    {
        if ($user) {
            $this->authorize($user, 'activities.execute');
        }

        $end = $actualEnd ?? $activity->end_date;

        DB::transaction(function () use ($activity, $user, $end) {
            $this->move($activity, ActivityStatus::Completed, $user ? 'Completion confirmed' : 'End date passed', $user, $user ? null : 'System', [
                'completed_at' => now(),
                'actual_end_date' => $end,
                'report_due_on' => WorkingDays::add($end, $this->settings->int('report_due_working_days')),
            ]);
            $this->costing->refresh($activity);
        });
    }

    /**
     * RP-02: the back-to-office report has been received.
     *
     * @param  array{received_on: string, outputs_achieved: string, findings?: ?string, recommendations?: ?string}  $data
     */
    public function recordReport(Activity $activity, array $data, ?UploadedFile $file, User $user): ReportReceipt
    {
        $this->authorize($user, 'activities.execute');

        if ($activity->status !== ActivityStatus::Completed) {
            throw new ActivityWorkflowException('A report can be recorded once the activity is completed.');
        }

        return DB::transaction(function () use ($activity, $data, $file, $user) {
            $document = $file ? $this->documents->store($activity, $file, ActivityDocument::KIND_REPORT, $user) : null;

            $receipt = ReportReceipt::create([
                'activity_id' => $activity->id,
                'received_on' => $data['received_on'],
                'outputs_achieved' => $data['outputs_achieved'],
                'findings' => $data['findings'] ?? null,
                'recommendations' => $data['recommendations'] ?? null,
                'document_id' => $document?->id,
                'recorded_by' => $user->id,
            ]);

            $this->move($activity, ActivityStatus::ReportReceived, 'Back-to-office report received', $user);

            return $receipt;
        });
    }

    /** FR-04 / BR-08: close only when the report is in and attendance and actual costs are recorded. */
    public function close(Activity $activity, User $user): void
    {
        $this->authorize($user, 'activities.close');

        $pending = $activity->participants()->whereNotIn('status', ParticipationStatus::values(ParticipationStatus::Attended, ParticipationStatus::Absent, ParticipationStatus::Withdrawn))->count();
        if ($pending > 0) {
            throw new ActivityWorkflowException("Record attendance for every participant first ({$pending} pending).");
        }

        if ($activity->costs()->whereNull('actual_amount')->exists()) {
            throw new ActivityWorkflowException('Record the actual amount on every cost line before closing (enter 0 where nothing was spent).');
        }

        $this->move($activity, ActivityStatus::Closed, null, $user, extra: ['closed_at' => now(), 'closed_by' => $user->id]);
    }

    /**
     * PT-07 / BR-10: record a change made after submission or approval.
     * Returns true when the change exceeded tolerance and the activity went back to the CEO.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function recordAmendment(Activity $activity, string $kind, string $summary, string $reason, User $user, bool $beyondTolerance, ?array $before = null, ?array $after = null): bool
    {
        $this->requireReason($reason, 'Give a reason for changing an approved activity.');

        $returns = $beyondTolerance && in_array($activity->status, [ActivityStatus::Approved, ActivityStatus::InProgress, ActivityStatus::Postponed], true);

        DB::transaction(function () use ($activity, $kind, $summary, $reason, $user, $returns, $before, $after) {
            ActivityAmendment::create([
                'activity_id' => $activity->id,
                'kind' => $kind,
                'summary' => $summary,
                'reason' => $reason,
                'before' => $before,
                'after' => $after,
                'returned_for_decision' => $returns,
                'user_id' => $user->id,
            ]);

            if ($returns) {
                $this->move($activity, ActivityStatus::AwaitingDecision, "Amendment beyond tolerance: {$summary}", $user);
                $this->alerts->toCeo(ActivityAlert::awaitingDecision($activity, amended: true));
            }
        });

        return $returns;
    }

    /** BR-10 cost check: planned total now against the total when approved. */
    public function costBeyondTolerance(Activity $activity): bool
    {
        if ($activity->approved_estimated_total === null) {
            return false;
        }

        $change = Money::percentChange(Money::toCents($activity->approved_estimated_total), Money::toCents($activity->estimated_total));

        return $change !== null && $change > $this->settings->int('tolerance_cost_increase_percent');
    }

    /** Daily: move in-progress activities whose end date has passed to Completed (RP-01). */
    public function completeEndedActivities(): int
    {
        $count = 0;
        Activity::query()->status(ActivityStatus::InProgress)->whereDate('end_date', '<', today())->each(function (Activity $activity) use (&$count) {
            $this->complete($activity);
            $count++;
        });

        return $count;
    }

    /**
     * @return list<string>
     */
    private function reschedule(Activity $activity, Carbon $start, int $days, string $reason, User $user, string $kind): array
    {
        if ($days < 1) {
            throw new ActivityWorkflowException('An activity lasts at least one day.');
        }

        $before = ['start_date' => $activity->start_date->toDateString(), 'end_date' => $activity->end_date->toDateString(), 'days' => $activity->days];
        $end = $start->copy()->addDays($days - 1);
        $after = ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'days' => $days];

        $shift = abs((int) $activity->start_date->diffInDays($start, false));
        $extra = $days - $activity->days;
        $beyond = $kind === ActivityAmendment::KIND_EXTENSION
            ? $extra > $this->settings->int('tolerance_extension_days')
            : $shift > $this->settings->int('tolerance_date_shift_days') || $extra > $this->settings->int('tolerance_extension_days');

        $warnings = [];

        DB::transaction(function () use ($activity, $start, $end, $days, $reason, $user, $kind, $before, $after, $beyond, &$warnings) {
            $wasPostponed = $activity->status === ActivityStatus::Postponed;

            $activity->forceFill(['start_date' => $start, 'end_date' => $end, 'days' => $days, 'nights' => max(0, $days - 1)])->save();
            $warnings = $this->dsa->recalculate($activity);

            $summary = $kind === ActivityAmendment::KIND_EXTENSION
                ? sprintf('Extended by %d day(s) to %s', $days - $before['days'], $end->format('d M Y'))
                : sprintf('Dates moved to %s – %s', $start->format('d M Y'), $end->format('d M Y'));

            $returned = $this->recordAmendment($activity, $kind, $summary, $reason, $user, $beyond, $before, $after);

            if (! $returned && $wasPostponed) {
                $this->move($activity, ActivityStatus::Approved, "Rescheduled: {$summary}", $user, extra: ['status_reason' => null]);
            }

            foreach ($this->checks->conflictsFor($activity) as $conflicts) {
                $warnings[] = 'Overlap on the new dates with '.$conflicts->pluck('reference')->implode(', ').'.';
            }
        });

        return $warnings;
    }

    private function applyDecision(Activity $activity, DecisionType $decision, ?string $comment, DecisionMode $mode, User $user, ?string $memoReference = null, ?Carbon $memoDate = null, ?int $documentId = null): CeoDecision
    {
        if ($decision->requiresComment() && trim((string) $comment) === '') {
            throw new ActivityWorkflowException('A comment is required to decline or return an activity.');
        }

        if ($decision === DecisionType::Approved) {
            $this->assertHasTeam($activity);
        }

        return DB::transaction(function () use ($activity, $decision, $comment, $mode, $user, $memoReference, $memoDate, $documentId) {
            $record = CeoDecision::create([
                'activity_id' => $activity->id,
                'decision' => $decision,
                'comment' => $comment ? trim($comment) : null,
                'mode' => $mode,
                'memo_reference' => $memoReference,
                'memo_date' => $memoDate,
                'document_id' => $documentId,
                'recorded_by' => $user->id,
            ]);

            $extra = ['status_reason' => $decision === DecisionType::Approved ? null : $comment];
            if ($decision === DecisionType::Approved) {
                $decidedOn = $memoDate ?? today();
                $extra += [
                    'approval_mode' => $mode,
                    'approval_reference' => $memoReference,
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                    'approved_estimated_total' => $activity->estimated_total,
                    // CD-06: the activity started before the CEO approved it (the memo date for a decision given outside the system).
                    'is_retrospective' => $decidedOn->gt($activity->start_date),
                ];
            }

            $label = $mode === DecisionMode::RecordedOnBehalf ? "{$decision->label()} (recorded on the CEO's behalf, memo {$memoReference})" : $decision->label();
            $this->move($activity, $decision->status(), trim($label.($comment ? ": {$comment}" : '')), $user, extra: $extra);

            $this->alerts->toStaffOfCeo(ActivityAlert::decided($activity, $decision));

            return $record;
        });
    }

    private function assertReadyForDecision(Activity $activity): void
    {
        $this->assertHasTeam($activity);

        if ($activity->locations()->doesntExist()) {
            throw new ActivityWorkflowException('Add at least one location before submitting.');
        }

        if ($activity->documents()->where('kind', ActivityDocument::KIND_MEMO)->doesntExist()) {
            throw new ActivityWorkflowException('Attach the source document (memo, concept note or terms of reference) before submitting.');
        }

        $unexplained = collect($this->checks->conflictsFor($activity))
            ->keys()
            ->filter(fn (int $participantId) => blank($activity->participants->firstWhere('id', $participantId)?->conflict_reason));

        if ($unexplained->isNotEmpty()) {
            throw new ActivityWorkflowException('Some participants are already on another activity on these dates. Give a reason for each before submitting.');
        }
    }

    private function assertHasTeam(Activity $activity): void
    {
        if ($activity->participants()->where('is_external', false)->doesntExist()) {
            throw new ActivityWorkflowException('Add at least one staff participant first.');
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function move(Activity $activity, ActivityStatus $to, ?string $reason, ?User $user, ?string $actorName = null, array $extra = []): void
    {
        $from = $activity->status;

        if (! $from->canTransitionTo($to)) {
            throw new ActivityWorkflowException("An activity that is {$from->label()} cannot be moved to {$to->label()}.");
        }

        DB::transaction(function () use ($activity, $from, $to, $reason, $user, $actorName, $extra) {
            $activity->forceFill(['status' => $to] + $extra)->save();
            $this->history($activity, $from, $to, $reason, $user, $actorName);
        });
    }

    private function history(Activity $activity, ?ActivityStatus $from, ActivityStatus $to, ?string $reason, ?User $user, ?string $actorName): void
    {
        ActivityStatusHistory::create([
            'activity_id' => $activity->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'user_id' => $user?->id,
            'actor_name' => $user ? null : $actorName,
        ]);
    }

    private function authorize(User $user, string $permission): void
    {
        if (! $user->can($permission)) {
            throw new AuthorizationException('You are not permitted to do this.');
        }
    }

    private function requireReason(string $reason, string $message): void
    {
        if (trim($reason) === '') {
            throw new ActivityWorkflowException($message);
        }
    }
}
