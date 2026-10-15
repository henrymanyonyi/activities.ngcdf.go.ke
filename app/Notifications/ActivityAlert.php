<?php

namespace App\Notifications;

use App\Enums\DecisionType;
use App\Enums\ReviewOffice;
use App\Models\Activity;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * FRD NT-01: in-app alert to an authorised user. Database channel only —
 * nothing leaves the system (NT-03, BR-11). Each message says what to do.
 */
class ActivityAlert extends Notification
{
    use Queueable;

    public function __construct(
        public string $kind,
        public string $message,
        public ?int $activityId = null,
        public string $icon = 'fa-bell',
    ) {}

    public static function awaitingDecision(Activity $activity, bool $amended = false): self
    {
        return new self('awaiting_decision', ($amended ? 'Amended, back for decision: ' : 'Awaiting your decision: ').$activity->title, $activity->id, 'fa-hourglass-half');
    }

    public static function decided(Activity $activity, DecisionType $decision): self
    {
        return new self('decided', "{$decision->label()}: {$activity->title}", $activity->id, 'fa-gavel');
    }

    public static function submittedForReview(Activity $activity, bool $resubmitted = false): self
    {
        return new self('submitted_for_review', ($resubmitted ? 'Corrected and resubmitted, please review: ' : 'Submitted for your review: ').$activity->title, $activity->id, 'fa-magnifying-glass');
    }

    public static function returnedForCorrection(Activity $activity): self
    {
        return new self('returned_for_correction', "Returned for correction, please revise and resubmit: {$activity->title}", $activity->id, 'fa-reply');
    }

    public static function forwardedToOffice(Activity $activity, ReviewOffice $office): self
    {
        return new self('forwarded_for_review', "Forwarded to you by the {$office->label()}'s office, please review: {$activity->title}", $activity->id, 'fa-share');
    }

    /** To the Head of Department: their memo has moved on. No action needed. */
    public static function progressed(Activity $activity, string $where): self
    {
        return new self('memo_progressed', "{$where}: {$activity->title}", $activity->id, 'fa-share');
    }

    public static function pendingTooLong(Activity $activity, int $workingDays): self
    {
        return new self('memo_pending', "Pending {$workingDays}+ working days, please act: {$activity->title}", $activity->id, 'fa-clock');
    }

    public static function commencementDue(Activity $activity): self
    {
        return new self('commencement_due', "Confirm commencement: {$activity->title}", $activity->id, 'fa-person-walking-luggage');
    }

    public static function reportOverdue(Activity $activity): self
    {
        return new self('report_overdue', "Back-to-office report overdue: {$activity->title}", $activity->id, 'fa-file-circle-exclamation');
    }

    public static function linkSubmission(Activity $activity): self
    {
        return new self('link_submission', "New submission to review: {$activity->title}", $activity->id, 'fa-inbox');
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
        ];
    }
}
