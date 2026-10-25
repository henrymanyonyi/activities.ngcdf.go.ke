<?php

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\DecisionMode;
use App\Enums\DecisionType;
use App\Enums\ParticipationStatus;
use App\Enums\ReviewOffice;
use App\Enums\ReviewRecommendation;
use App\Exceptions\ActivityWorkflowException;
use App\Models\Activity;
use App\Models\ActivityAmendment;
use App\Models\ActivityDocument;
use App\Models\ActivityReviewNote;
use App\Models\ActivityStatusHistory;
use App\Models\CeoDecision;
use App\Models\ReportReceipt;
use App\Models\User;
use App\Notifications\ActivityAlert;
use App\Support\DsaPeriod;
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
        private MemoDocument $memoDocument,
    ) {}

    /** Record the starting status of a newly created activity. */
    public function begin(Activity $activity, ?User $user = null, ?string $actorName = null): void
    {
        $activity->forceFill(['status' => ActivityStatus::Draft])->saveQuietly();
        $this->history($activity, null, ActivityStatus::Draft, null, $user, $actorName);
    }

    /** CD-01: Chief of Staff / Assistant submit to the CEO's decision queue (see forward()). */
    public function submitForDecision(Activity $activity, User $user): void
    {
        $this->forward($activity, $user);
    }

    // ── Activity memo workflow ─────────────────────────────────────────

    /**
     * A Head of Department submits their memo for review by the Assistant
     * Chief of Staff or the Chief of Staff, or resubmits it after correcting
     * it. A resubmission goes back to the office that returned it.
     */
    public function submitForReview(Activity $activity, User $user, ?ReviewOffice $office = null, ?string $note = null, bool $acting = false): void
    {
        $this->assertHeadMaySubmit($activity, $user);

        $resubmitted = $activity->status === ActivityStatus::ReturnedToHod;
        $office = ($resubmitted ? $activity->review_office : null) ?? $office ?? ReviewOffice::AssistantChiefOfStaff;
        $note = trim((string) $note);
        $reason = match (true) {
            $resubmitted => 'Corrected and resubmitted to the '.$office->label(),
            $activity->status === ActivityStatus::WithHod => "Reviewed by the Head of Department and forwarded, on behalf of {$activity->submitted_by_name}, for review by the ".$office->label(),
            default => 'Submitted for review by the '.$office->label(),
        }.($note !== '' ? ": {$note}" : '');

        $this->move($activity, ActivityStatus::UnderReview, $reason, $user, extra: ['review_office' => $office, 'status_reason' => null] + $this->signing($user, $acting));
        $this->alerts->toOffice($office, ActivityAlert::submittedForReview($activity, $resubmitted));
    }

    private function assertHeadMaySubmit(Activity $activity, User $user): void
    {
        $this->authorize($user, 'memos.originate');
        $this->assertDepartmentMemo($activity, $user);

        if (! $activity->status->isWithDepartment()) {
            throw new ActivityWorkflowException('This memo has already been submitted. It is now '.strtolower($activity->status->label()).'.');
        }
        if ($activity->isWithPreparer()) {
            throw new ActivityWorkflowException("{$activity->submitted_by_name} is still preparing this memo. It comes to you for review when they submit it.");
        }

        $this->assertReadyForReview($activity);
    }

    /**
     * How the Head signs this submission: their substantive designation from the staff list, and whether
     * they sign as acting ("Ag."). Kept on the memo only; the officer's own record is never changed.
     *
     * @return array{hod_designation: string, hod_acting: bool}
     */
    private function signing(User $user, bool $acting): array
    {
        return ['hod_designation' => $user->signingDesignation(), 'hod_acting' => $acting];
    }

    /**
     * An officer submits the memo they prepared through a Head of Department's delegated link to that
     * Head for review (App\Services\DelegatedMemos, which checks the link). Nothing blocking may be missing.
     */
    public function submitToHod(Activity $activity, string $preparerName): void
    {
        if (! in_array($activity->status, [ActivityStatus::Draft, ActivityStatus::ReturnedToPreparer], true) || ! $activity->isDelegated()) {
            throw new ActivityWorkflowException('This memo has already been submitted to the Head of Department.');
        }
        $this->assertReadyForReview($activity, byHead: false);

        $again = $activity->status === ActivityStatus::ReturnedToPreparer;
        $this->move($activity, ActivityStatus::WithHod, ($again ? 'Corrected and resubmitted to the Head of Department by ' : 'Prepared and submitted to the Head of Department by ').$preparerName, null, $preparerName, ['status_reason' => null]);
        $this->alerts->toDepartment($activity, ActivityAlert::preparedForHod($activity, $again));
    }

    /** The Head of Department sends the memo an officer prepared back to them, saying what to change. */
    public function returnToPreparer(Activity $activity, string $reason, User $user): void
    {
        $this->assertHeadReviewing($activity, $user);
        $this->requireReason($reason, 'Say what the officer must change.');

        $this->move($activity, ActivityStatus::ReturnedToPreparer, trim($reason), $user, extra: ['status_reason' => trim($reason)]);
    }

    /** The Head of Department declines the memo an officer prepared. It is kept, read only, with the reason. */
    public function declineByHod(Activity $activity, string $reason, User $user): void
    {
        $this->assertHeadReviewing($activity, $user);
        $this->requireReason($reason, 'Give the reason for declining the memo.');

        $this->move($activity, ActivityStatus::Declined, 'Declined by the Head of Department: '.trim($reason), $user, extra: ['status_reason' => trim($reason)]);
    }

    private function assertHeadReviewing(Activity $activity, User $user): void
    {
        $this->authorize($user, 'memos.originate');
        $this->assertDepartmentMemo($activity, $user);
        if ($user->can('activities.view') && ! $user->isHeadOfDepartment()) {
            throw new AuthorizationException('Only the Head of Department reviews a memo an officer prepared for them.');
        }
        if ($activity->status !== ActivityStatus::WithHod) {
            throw new ActivityWorkflowException('Only a memo with you for review can be returned to the officer or declined.');
        }
    }

    /** The reviewer sends the memo back to the Head of Department, saying what to correct. */
    public function returnForCorrection(Activity $activity, string $reason, User $user): void
    {
        $this->authorize($user, 'memos.review');
        $this->requireReason($reason, 'Say what the Head of Department must correct.');

        if (! in_array($activity->status, [ActivityStatus::UnderReview, ActivityStatus::Returned], true)) {
            throw new ActivityWorkflowException('Only a memo under review, or one the CEO returned, can be returned for correction.');
        }

        $this->assertMayReview($activity, $user);

        if (! $this->departmentHasHead($activity)) {
            throw new ActivityWorkflowException('There is no active Head of Department account for this department to correct the memo. Make the corrections yourself instead.');
        }

        $office = ReviewOffice::forUser($user) ?? $activity->review_office ?? ReviewOffice::AssistantChiefOfStaff;
        $reason = trim($reason);

        $this->move($activity, ActivityStatus::ReturnedToHod, $reason, $user, extra: ['status_reason' => $reason, 'review_office' => $office]);
        $this->alerts->toDepartment($activity, ActivityAlert::returnedForCorrection($activity));
    }

    /**
     * Send the memo on: to the CEO's decision queue, or, when the Assistant
     * forwards and Settings require it, to the Chief of Staff first. Works
     * from a draft the Office of the CEO prepared, a memo under review, or
     * one the CEO returned. Returns where it went.
     */
    public function forward(Activity $activity, User $user, ?ReviewRecommendation $recommendation = null, ?string $comment = null): string
    {
        $this->authorize($user, 'activities.submit');

        if (! in_array($activity->status, [ActivityStatus::Draft, ActivityStatus::UnderReview, ActivityStatus::Returned], true)) {
            throw new ActivityWorkflowException("A memo that is {$activity->status->label()} cannot be forwarded.");
        }

        if ($activity->isDepartmentDraft()) {
            throw new ActivityWorkflowException('The Head of Department has not submitted this memo yet.');
        }

        if ($activity->status === ActivityStatus::UnderReview) {
            $this->assertMayReview($activity, $user);
        }

        $comment = trim((string) $comment);
        $viaChiefOfStaff = ReviewOffice::forUser($user) === ReviewOffice::AssistantChiefOfStaff && $this->settings->bool('acos_forwards_via_cos');

        if ($viaChiefOfStaff) {
            DB::transaction(function () use ($activity, $user, $recommendation, $comment) {
                $this->note($activity, $user, $comment, $recommendation);

                if ($activity->status === ActivityStatus::UnderReview) {
                    $this->lockAt($activity, ActivityStatus::UnderReview);
                    $activity->forceFill(['review_office' => ReviewOffice::ChiefOfStaff])->save();
                    $this->history($activity, ActivityStatus::UnderReview, ActivityStatus::UnderReview, 'Forwarded to the Chief of Staff', $user, null);
                } else {
                    $this->move($activity, ActivityStatus::UnderReview, 'Forwarded to the Chief of Staff', $user, extra: ['review_office' => ReviewOffice::ChiefOfStaff, 'status_reason' => null]);
                }
            });

            $this->alerts->toOffice(ReviewOffice::ChiefOfStaff, ActivityAlert::forwardedToOffice($activity, ReviewOffice::AssistantChiefOfStaff));
            $this->alerts->toDepartment($activity, ActivityAlert::progressed($activity, 'With the Chief of Staff for review'));

            return 'the Chief of Staff';
        }

        $this->assertReadyForDecision($activity);

        DB::transaction(function () use ($activity, $user, $recommendation, $comment) {
            $this->note($activity, $user, $comment, $recommendation);
            $this->move($activity, ActivityStatus::AwaitingDecision, 'Forwarded to the CEO', $user, extra: ['review_office' => null, 'status_reason' => null]);
        });

        $this->alerts->toCeo(ActivityAlert::awaitingDecision($activity));
        $this->alerts->toDepartment($activity, ActivityAlert::progressed($activity, 'With the CEO for decision'));

        return 'the CEO';
    }

    /** A review comment or recommendation, kept as written (never edited or deleted). */
    public function addReviewNote(Activity $activity, string $body, ?ReviewRecommendation $recommendation, User $user): ActivityReviewNote
    {
        $this->authorize($user, 'memos.review');
        $this->requireReason($body, 'Write the comment first.');

        if (! $activity->status->isPendingDecision() && $activity->status !== ActivityStatus::ReturnedToHod) {
            throw new ActivityWorkflowException('Comments can be added while the memo is being reviewed or decided.');
        }

        return $this->note($activity, $user, trim($body), $recommendation);
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
            $this->assertConflictsExplained($activity, 'approving');
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

    /** CF-10: a Draft may be discarded; anything else is cancelled. A Head of Department may discard only their own department's draft. */
    public function discardDraft(Activity $activity, User $user): void
    {
        if (! $user->can('activities.manage')) {
            $this->authorize($user, 'memos.originate');
            $this->assertDepartmentMemo($activity, $user);

            if (! $activity->isDepartmentDraft()) {
                throw new AuthorizationException('Only the Office of the CEO can discard a draft it prepared.');
            }
        }

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
        if ($activity->report()->exists()) {
            throw new ActivityWorkflowException('The department has submitted its report. Accept it, or return it for correction.');
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

    /**
     * The Head of Department's activity report once the activity is complete,
     * with what was actually spent on each cost line (business owner, 21
     * October 2026). The actual amounts count as spent at once; the report
     * waits for the Office of the CEO to accept it or return it for
     * correction. A returned report is corrected and submitted again.
     *
     * @param  array{outputs_achieved: string, findings?: ?string, recommendations?: ?string}  $data
     * @param  array<int|string, string|null>  $actuals  cost line id => amount spent
     */
    public function submitReport(Activity $activity, array $data, array $actuals, ?UploadedFile $file, User $user): ReportReceipt
    {
        $this->authorize($user, 'memos.originate');
        if (! $activity->isVisibleTo($user)) {
            throw new AuthorizationException('This activity is not your department\'s.');
        }
        if ($activity->status !== ActivityStatus::Completed) {
            throw new ActivityWorkflowException('The report is submitted once the activity is complete.');
        }
        if (trim($data['outputs_achieved']) === '') {
            throw new ActivityWorkflowException('Say what the activity achieved.');
        }

        $lines = $activity->costs()->get();
        if ($lines->contains(fn ($line) => blank($actuals[$line->id] ?? null))) {
            throw new ActivityWorkflowException('Enter what was actually spent on every cost line (0 if nothing was spent).');
        }

        return DB::transaction(function () use ($activity, $data, $actuals, $file, $user, $lines) {
            $this->lockAt($activity, ActivityStatus::Completed);
            $existing = ReportReceipt::query()->where('activity_id', $activity->id)->lockForUpdate()->first();
            if ($existing && ! $existing->isReturned()) {
                throw new ActivityWorkflowException($existing->isSubmitted()
                    ? 'The report has been submitted and is waiting for the Office of the CEO.'
                    : 'The report has already been accepted.');
            }

            $document = $file ? $this->documents->store($activity, $file, ActivityDocument::KIND_REPORT, $user) : null;

            $receipt = $existing ?? new ReportReceipt(['activity_id' => $activity->id]);
            $receipt->fill([
                // Timed from the first submission: a report returned for correction still came in on that day.
                'received_on' => $existing?->received_on ?? today(),
                'outputs_achieved' => trim($data['outputs_achieved']),
                'findings' => ($data['findings'] ?? null) ?: null,
                'recommendations' => ($data['recommendations'] ?? null) ?: null,
                'document_id' => $document?->id ?? $existing?->document_id,
                'recorded_by' => $user->id,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
            ])->forceFill(['status' => ReportReceipt::STATUS_SUBMITTED, 'return_reason' => null])->save();

            foreach ($lines as $line) {
                $line->update([
                    'actual_amount' => Money::fromCents(Money::toCents($actuals[$line->id])),
                    'actual_recorded_by' => $user->id,
                ]);
            }
            $this->costing->refresh($activity);

            $alert = ActivityAlert::reportSubmitted($activity, $existing !== null);
            $this->alerts->toCeo($alert);
            $this->alerts->toStaffOfCeo($alert);

            return $receipt;
        });
    }

    /** The Office of the CEO accepts the department's report: the activity moves to Report received. */
    public function acceptReport(Activity $activity, User $user): void
    {
        $this->authorize($user, 'activities.execute');

        DB::transaction(function () use ($activity, $user) {
            $receipt = ReportReceipt::query()->where('activity_id', $activity->id)->lockForUpdate()->first();
            if (! $receipt?->isSubmitted()) {
                throw new ActivityWorkflowException('There is no submitted report waiting to be accepted.');
            }

            $receipt->forceFill(['status' => ReportReceipt::STATUS_ACCEPTED, 'reviewed_by' => $user->id, 'reviewed_at' => now()])->save();
            $this->move($activity, ActivityStatus::ReportReceived, 'Activity report accepted', $user);
            $this->alerts->toDepartment($activity, ActivityAlert::progressed($activity, 'Report accepted by the Office of the CEO'));
        });
    }

    /** The Office of the CEO returns the department's report for correction; the activity stays Completed. */
    public function returnReport(Activity $activity, string $reason, User $user): void
    {
        $this->authorize($user, 'activities.execute');
        $this->requireReason($reason, 'Say what needs to be corrected in the report.');

        DB::transaction(function () use ($activity, $reason, $user) {
            $receipt = ReportReceipt::query()->where('activity_id', $activity->id)->lockForUpdate()->first();
            if (! $receipt?->isSubmitted()) {
                throw new ActivityWorkflowException('There is no submitted report waiting for review.');
            }

            $receipt->forceFill(['status' => ReportReceipt::STATUS_RETURNED, 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'return_reason' => trim($reason)])->save();
            $this->alerts->toDepartment($activity, ActivityAlert::reportReturned($activity));
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

    /**
     * Daily: an approved activity is under way from its start date (business
     * owner, 21 October 2026). Postponed and cancelled ones are not approved,
     * so they are left alone.
     */
    public function startDueActivities(): int
    {
        $count = 0;
        Activity::query()->status(ActivityStatus::Approved)->whereDate('start_date', '<=', today())->each(function (Activity $activity) use (&$count) {
            $this->move($activity, ActivityStatus::InProgress, 'Start date reached', null, 'System', [
                'commenced_at' => now(),
                'actual_start_date' => $activity->start_date,
            ]);
            $count++;
        });

        return $count;
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

            $shift = (int) $activity->start_date->diffInDays($start, false);
            $activity->forceFill(['start_date' => $start, 'end_date' => $end, 'days' => $days, 'nights' => DsaPeriod::nightsFor($days)])->save();
            $this->moveParticipantDates($activity, $shift);
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

    /**
     * Keep each participant's own days in step when the activity moves: shift
     * them by the same number of days, then fit them inside the new dates.
     */
    private function moveParticipantDates(Activity $activity, int $shift): void
    {
        $activity->participants()->whereNotNull('start_date')->get()->each(function ($p) use ($activity, $shift) {
            $start = $p->start_date->copy()->addDays($shift)->max($activity->start_date)->min($activity->end_date);
            $end = $p->end_date->copy()->addDays($shift)->max($start)->min($activity->end_date);
            $whole = $start->equalTo($activity->start_date) && $end->equalTo($activity->end_date);

            $p->update([
                'start_date' => $whole ? null : $start,
                'end_date' => $whole ? null : $end,
                'days_planned' => $whole ? null : (int) $start->diffInDays($end) + 1,
            ]);
        });
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
            if ($decision === DecisionType::Approved) {
                // Under a lock on the team, so two activities sharing an officer cannot both be approved at once.
                $this->checks->lockStaff($activity->participants()->whereNotNull('staff_id')->pluck('staff_id')->all());
                $this->assertConflictsExplained($activity, 'approving');
            }

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

            $this->alerts->toStaffOfCeo(ActivityAlert::decidedForOffice($activity, $decision));
            $this->alerts->toDepartment($activity, ActivityAlert::decidedForDepartment($activity, $decision));

            return $record;
        });
    }

    /**
     * What a reviewer needs before a Head of Department's memo can be
     * submitted: the generated memo must have nothing blocking missing. It
     * stands as the memo, so no signed upload is needed (business owner's
     * decision, 21 October 2026).
     */
    /** $byHead: false for an officer submitting to their Head, who adds the memo reference and issue date. */
    private function assertReadyForReview(Activity $activity, bool $byHead = true): void
    {
        $missing = $this->memoDocument->blockingIssues($activity, $byHead);

        if ($missing !== []) {
            throw new ActivityWorkflowException('Before submitting: '.implode(' ', $missing));
        }
    }

    /** A Head of Department acts only on their own department's memos. */
    private function assertDepartmentMemo(Activity $activity, User $user): void
    {
        if (! $activity->isVisibleTo($user)) {
            throw new AuthorizationException('This memo belongs to another department.');
        }
    }

    /**
     * The office holding a memo under review acts on it. The Chief of Staff
     * (and the CEO, who holds every permission) may also act on a memo with
     * the Assistant; the Assistant may not act on one with the Chief of Staff.
     */
    private function assertMayReview(Activity $activity, User $user): void
    {
        if ($activity->status === ActivityStatus::UnderReview
            && $activity->review_office === ReviewOffice::ChiefOfStaff
            && ReviewOffice::forUser($user) === ReviewOffice::AssistantChiefOfStaff) {
            throw new ActivityWorkflowException('This memo is with the Chief of Staff for review.');
        }
    }

    private function departmentHasHead(Activity $activity): bool
    {
        return User::query()->role(User::ROLE_HOD)->where('is_active', true)
            ->where(fn ($q) => $q->where('department_id', $activity->organising_department_id)->when($activity->responsible_hod_id, fn ($q, $id) => $q->orWhereKey($id)))
            ->exists();
    }

    private function note(Activity $activity, User $user, string $body, ?ReviewRecommendation $recommendation): ?ActivityReviewNote
    {
        if ($body === '' && ! $recommendation) {
            return null;
        }

        return ActivityReviewNote::create([
            'activity_id' => $activity->id,
            'user_id' => $user->id,
            'recommendation' => $recommendation,
            'body' => $body !== '' ? $body : $recommendation->label(),
        ]);
    }

    private function assertReadyForDecision(Activity $activity): void
    {
        $this->assertHasTeam($activity);

        if ($activity->locations()->doesntExist()) {
            throw new ActivityWorkflowException('Add at least one location before submitting.');
        }

        // A memo the Head of Department prepared in the system is the generated memo itself.
        if (! $activity->isDepartmentOriginated() && $activity->documents()->where('kind', ActivityDocument::KIND_MEMO)->doesntExist()) {
            throw new ActivityWorkflowException('Attach the source document (memo, concept note or terms of reference) before submitting.');
        }

        $this->assertConflictsExplained($activity, 'submitting');
    }

    /**
     * BR-04 at every gate: nobody may stay on two overlapping approved or
     * in-progress activities without a recorded reason. Overlaps can appear
     * after submission (another activity is approved, dates move), so this
     * runs again at approval.
     */
    private function assertConflictsExplained(Activity $activity, string $before): void
    {
        $activity->load('participants');

        $names = collect($this->checks->conflictsFor($activity))
            ->keys()
            ->map(fn (int $participantId) => $activity->participants->firstWhere('id', $participantId))
            ->filter(fn ($participant) => $participant && blank($participant->conflict_reason))
            ->map(fn ($participant) => $participant->displayName());

        if ($names->isNotEmpty()) {
            throw new ActivityWorkflowException(sprintf(
                '%s %s already on another approved activity on these dates. On the Team tab, give a reason for each before %s.',
                $names->implode(', '), $names->count() === 1 ? 'is' : 'are', $before,
            ));
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
            $this->lockAt($activity, $from);
            $activity->forceFill(['status' => $to] + $extra)->save();
            $this->history($activity, $from, $to, $reason, $user, $actorName);
        });
    }

    /**
     * Lock the row and confirm nobody moved the activity since it was loaded,
     * so a double click or two people acting at once cannot apply twice.
     */
    private function lockAt(Activity $activity, ActivityStatus $expected): void
    {
        $current = Activity::query()->whereKey($activity->id)->lockForUpdate()->toBase()->value('status');

        if ($current !== $expected->value) {
            throw new ActivityWorkflowException('Someone else changed this memo a moment ago. Reload the page to see where it is now.');
        }
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
