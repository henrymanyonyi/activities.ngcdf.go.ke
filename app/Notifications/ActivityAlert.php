<?php

namespace App\Notifications;

use App\Enums\DecisionType;
use App\Enums\ReviewOffice;
use App\Models\ActionInstruction;
use App\Models\Activity;
use App\Models\Department;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * FRD NT-01: in-app alert to an authorised user, on the database channel.
 * Each message says what to do. An alert that needs the recipient to act
 * also carries a reference-only text ($outbound) that App\Services\OutboundAlerts
 * sends by email and SMS to Heads of Department, the Chief of Staff and the
 * Assistant (exception to NT-03 / BR-11 approved 25 October 2026): never the
 * title, places, team, costs or comments, only the reference and the step.
 */
class ActivityAlert extends Notification
{
    use Queueable;

    public function __construct(
        public string $kind,
        public string $message,
        public ?int $activityId = null,
        public string $icon = 'fa-bell',
        public ?string $url = null,
    ) {}

    /**
     * The reference-only email and SMS text, when the recipient must act. Null: in-app only.
     *
     * @var array{subject: string, line: string, todo: string}|null
     */
    public ?array $outbound = null;

    public ?string $reference = null;

    /** Marks the alert as needing action: also sent by email and SMS, with this text only. */
    public function needsAction(?string $reference, string $subject, string $line, string $todo): self
    {
        $this->reference = $reference;
        $this->outbound = ['subject' => $subject, 'line' => $line, 'todo' => $todo];

        return $this;
    }

    /**
     * Where the alert opens for this user: the activity page for the Office of the CEO,
     * the memo page for a Head of Department, or the alert's own page (budget, instruction).
     *
     * @param  array<string, mixed>  $data
     */
    public static function destination(array $data, User $user): string
    {
        $activityId = $data['activity_id'] ?? null;
        $url = $data['url'] ?? null;

        return match (true) {
            $activityId && $user->can('activities.view') => route('activities.show', $activityId),
            $activityId && $user->can('memos.originate') => route('memos.show', $activityId),
            $url && $user->canAny(['budgets.prepare', 'budgets.approve', 'instructions.action']) => $url,
            $url && ($data['kind'] ?? null) === 'staff_change_failed' && $user->can('staff.reassign') => $url,
            default => route('home'),
        };
    }

    /** About a department budget or a budget adjustment; opens the budget. */
    public static function budget(string $message, string $url, string $icon = 'fa-wallet'): self
    {
        return new self('budget', $message, null, $icon, $url);
    }

    public static function awaitingDecision(Activity $activity, bool $amended = false): self
    {
        return (new self('awaiting_decision', ($amended ? 'Amended, back for decision: ' : 'Awaiting your decision: ').$activity->title, $activity->id, 'fa-hourglass-half'))
            ->needsAction($activity->reference, "Memo {$activity->reference} awaiting your decision", $amended
                ? "Activity {$activity->reference} has been amended and is back for your decision."
                : "Memo {$activity->reference} is awaiting your decision.", 'Please approve, decline or return it.');
    }

    public static function decided(Activity $activity, DecisionType $decision): self
    {
        return new self('decided', "{$decision->label()}: {$activity->title}", $activity->id, 'fa-gavel');
    }

    /** To the Office of the CEO: a Returned decision comes back to them for clarification. */
    public static function decidedForOffice(Activity $activity, DecisionType $decision): self
    {
        $alert = self::decided($activity, $decision);

        return $decision === DecisionType::Returned
            ? $alert->needsAction($activity->reference, "Activity {$activity->reference} returned by the CEO", "The CEO has returned activity {$activity->reference} to the Office of the CEO for clarification.", 'Please read the CEO\'s directive and follow it up.')
            : $alert;
    }

    /** To the Head of Department: the CEO's decision on their memo. */
    public static function decidedForDepartment(Activity $activity, DecisionType $decision): self
    {
        $alert = self::progressed($activity, match ($decision) {
            DecisionType::Approved => 'Approved and authorised by the CEO',
            DecisionType::Declined => 'Declined by the CEO',
            DecisionType::Returned => 'Returned by the CEO to the Office of the CEO for clarification',
        });

        return match ($decision) {
            DecisionType::Approved => $alert->needsAction($activity->reference, "Memo {$activity->reference} approved", "Memo {$activity->reference} has been approved and authorised by the CEO.", 'Please prepare the team to go ahead, and submit the activity report with the amounts spent after it ends.'),
            DecisionType::Declined => $alert->needsAction($activity->reference, "Memo {$activity->reference} declined", "Memo {$activity->reference} has been declined by the CEO.", 'Please sign in to read the CEO\'s comment.'),
            DecisionType::Returned => $alert,
        };
    }

    public static function submittedForReview(Activity $activity, bool $resubmitted = false): self
    {
        return (new self('submitted_for_review', ($resubmitted ? 'Corrected and resubmitted, please review: ' : 'Submitted for your review: ').$activity->title, $activity->id, 'fa-magnifying-glass'))
            ->needsAction($activity->reference, "Memo {$activity->reference} to review", $resubmitted
                ? "Memo {$activity->reference} has been corrected and resubmitted for your review."
                : "Memo {$activity->reference} has been submitted for your review.", 'Please review it, then forward it or return it for correction.');
    }

    /** To the Head of Department: an officer submitted the memo they asked them to prepare. */
    public static function preparedForHod(Activity $activity, bool $again = false): self
    {
        return (new self('prepared_for_hod', ($again ? 'Corrected by the officer, please review: ' : 'Prepared by an officer, please review: ').$activity->title, $activity->id, 'fa-user-check'))
            ->needsAction($activity->reference, "Memo {$activity->reference} to review", $again
                ? "Memo {$activity->reference} has been corrected and resubmitted to you by the officer preparing it."
                : "Memo {$activity->reference}, prepared by an officer at your request, has been submitted to you.", 'Please review it, then forward it to the Chief of Staff, return it to the officer or decline it.');
    }

    public static function returnedForCorrection(Activity $activity): self
    {
        return (new self('returned_for_correction', "Returned for correction, please revise and resubmit: {$activity->title}", $activity->id, 'fa-reply'))
            ->needsAction($activity->reference, "Memo {$activity->reference} returned for correction", "Memo {$activity->reference} has been returned to you for correction.", 'Please make the changes asked for and resubmit it for review.');
    }

    public static function forwardedToOffice(Activity $activity, ReviewOffice $office): self
    {
        return (new self('forwarded_for_review', "Forwarded to you by the {$office->label()}'s office, please review: {$activity->title}", $activity->id, 'fa-share'))
            ->needsAction($activity->reference, "Memo {$activity->reference} forwarded to you", "Memo {$activity->reference} has been forwarded to you by the {$office->label()}.", 'Please review it, then forward it to the CEO or return it for correction.');
    }

    /** To the Head of Department: their memo has moved on. No action needed. */
    public static function progressed(Activity $activity, string $where): self
    {
        return new self('memo_progressed', "{$where}: {$activity->title}", $activity->id, 'fa-share');
    }

    /** To the Office of the CEO: a Head of Department submitted the activity report. */
    public static function reportSubmitted(Activity $activity, bool $resubmitted = false): self
    {
        return (new self('report_submitted', ($resubmitted ? 'Corrected report resubmitted, please review: ' : 'Activity report submitted, please review: ').$activity->title, $activity->id, 'fa-file-circle-check'))
            ->needsAction($activity->reference, "Activity report for {$activity->reference}", $resubmitted
                ? "The corrected activity report for {$activity->reference} has been resubmitted."
                : "The activity report for {$activity->reference} has been submitted.", 'Please accept it or return it for correction.');
    }

    /** To the Head of Department: their report came back. */
    public static function reportReturned(Activity $activity): self
    {
        return (new self('report_returned', "Report returned for correction, please revise and resubmit: {$activity->title}", $activity->id, 'fa-reply'))
            ->needsAction($activity->reference, "Activity report for {$activity->reference} returned", "The activity report for {$activity->reference} has been returned to you for correction.", 'Please correct the report or the amounts spent and submit it again.');
    }

    /** To the Head of the department instructed (Finance, Procurement or any other): opens the instruction sheet, never the memo. */
    public static function instructionSent(ActionInstruction $instruction): self
    {
        $reference = $instruction->activity->reference;
        $office = self::instructedOffice($instruction);

        if ($instruction->kind->needsWrittenInstruction()) {
            return (new self('instruction_sent', "The CEO instructs {$office} to act on {$reference}, {$instruction->activity->title}", null, $instruction->kind->icon(), route('instructions.show', $instruction)))
                ->needsAction($reference, "CEO's instruction on {$reference}", "The CEO has instructed {$office} to act on approved activity {$reference}.", 'Please open the instruction, acknowledge it, and record what was done.');
        }

        $action = mb_strtolower($instruction->kind->label());

        return (new self('instruction_sent', "The CEO instructs {$office}: {$instruction->kind->label()} for {$reference}, {$instruction->activity->title}", null, $instruction->kind->icon(), route('instructions.show', $instruction)))
            ->needsAction($reference, "CEO's instruction: {$action} for {$reference}", "The CEO instructs {$office} to {$action} for activity {$reference}.", 'Please acknowledge it, and record the '.mb_strtolower($instruction->kind->referenceLabel()).' when it is done.');
    }

    /** To the Office of the CEO and the memo's own department: the instructed department has done it. */
    public static function instructionCompleted(ActionInstruction $instruction): self
    {
        return new self('instruction_completed', self::instructedOffice($instruction)." has completed the CEO's instruction".($instruction->kind->needsWrittenInstruction() ? '' : " \"{$instruction->kind->label()}\"")." (ref. {$instruction->completion_reference}): {$instruction->activity->title}", $instruction->activity_id, $instruction->kind->icon());
    }

    /** "Finance", "Procurement", or the department's name for any other instruction. */
    private static function instructedOffice(ActionInstruction $instruction): string
    {
        return $instruction->kind->needsWrittenInstruction()
            ? (($instruction->relationLoaded('department') ? $instruction->department : $instruction->department()->first())?->name ?? 'the department')
            : $instruction->kind->office();
    }

    /** To an officer whose FAPM access was changed (App\Services\StaffChanges). */
    public static function accessChanged(?string $role, ?Department $department): self
    {
        $what = $role === null ? 'no longer has a role' : 'is now '.(User::ROLES[$role] ?? $role).($role === User::ROLE_HOD && $department ? ', '.$department->name : '');

        return (new self('access_changed', "Your FAPM access has changed: your account {$what}.", null, 'fa-user-shield', route('home')))
            ->needsAction(null, 'Your FAPM access has changed', 'Your FAPM access was changed on '.now()->format('d M Y').'.', 'Please sign in again to see what you can now do.');
    }

    /** To the officer who scheduled a change of position or access that could not be applied on its date. In the app only. */
    public static function scheduledStaffChangeFailed(string $officer, CarbonInterface $effectiveOn, string $reason): self
    {
        return new self('staff_change_failed', "The change for {$officer} from {$effectiveOn->format('d M Y')} could not be applied: {$reason}", null, 'fa-user-xmark', route('staff.index', ['search' => $officer]));
    }

    public static function pendingTooLong(Activity $activity, int $workingDays): self
    {
        return (new self('memo_pending', "Pending {$workingDays}+ working days, please act: {$activity->title}", $activity->id, 'fa-clock'))
            ->needsAction($activity->reference, "Reminder: memo {$activity->reference} is waiting", "Memo {$activity->reference} has been waiting for you for {$workingDays} or more working days.", 'Please act on it.');
    }

    public static function commencementDue(Activity $activity): self
    {
        return new self('commencement_due', "Confirm commencement: {$activity->title}", $activity->id, 'fa-person-walking-luggage');
    }

    public static function reportOverdue(Activity $activity): self
    {
        return (new self('report_overdue', "Back-to-office report overdue: {$activity->title}", $activity->id, 'fa-file-circle-exclamation'))
            ->needsAction($activity->reference, "Report overdue for {$activity->reference}", "The activity report for {$activity->reference} is overdue.", 'Please follow it up with the department.');
    }

    public static function linkSubmission(Activity $activity): self
    {
        return (new self('link_submission', "New submission to review: {$activity->title}", $activity->id, 'fa-inbox'))
            ->needsAction($activity->reference, "New submission {$activity->reference}", "A new submission, {$activity->reference}, has come in through a submission link.", 'Please review it.');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'message' => $this->message,
            'activity_id' => $this->activityId,
            'icon' => $this->icon,
            'url' => $this->url,
        ];
    }
}
