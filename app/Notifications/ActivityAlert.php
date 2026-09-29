<?php

namespace App\Notifications;

use App\Enums\DecisionType;
use App\Models\Activity;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * FRD NT-01: in-app alert to one of the three users. Database channel only —
 * nothing leaves the system (NT-03, BR-11).
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
