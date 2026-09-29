<?php

namespace App\Enums;

/**
 * FRD Section 5. Every change goes through App\Services\ActivityLifecycle,
 * which checks canTransitionTo() and writes activity_status_histories — never
 * update `status` directly.
 */
enum ActivityStatus: string
{
    case Draft = 'draft';
    case AwaitingDecision = 'awaiting_decision';
    case Approved = 'approved';
    case Returned = 'returned';
    case Declined = 'declined';
    case InProgress = 'in_progress';
    case Postponed = 'postponed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case ReportReceived = 'report_received';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::AwaitingDecision => 'Awaiting decision',
            self::Approved => 'Approved',
            self::Returned => 'Returned',
            self::Declined => 'Declined',
            self::InProgress => 'In progress',
            self::Postponed => 'Postponed',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
            self::ReportReceived => 'Report received',
            self::Closed => 'Closed',
        };
    }

    /** PAKA-RANGI §3.4: slate draft, amber pending, blue in flight, emerald done, red stopped. */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-100 text-slate-500',
            self::AwaitingDecision => 'bg-amber-50 text-amber-700',
            self::Approved => 'bg-teal-50 text-teal-700',
            self::Returned => 'bg-orange-50 text-orange-700',
            self::Declined, self::Cancelled => 'bg-red-50 text-red-700',
            self::InProgress => 'bg-blue-50 text-blue-700',
            self::Postponed => 'bg-purple-50 text-purple-700',
            self::Completed => 'bg-indigo-50 text-indigo-700',
            self::ReportReceived => 'bg-emerald-50 text-emerald-700',
            self::Closed => 'bg-slate-100 text-slate-600',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Draft => 'fa-pen-ruler',
            self::AwaitingDecision => 'fa-hourglass-half',
            self::Approved => 'fa-circle-check',
            self::Returned => 'fa-rotate-left',
            self::Declined => 'fa-circle-xmark',
            self::InProgress => 'fa-person-walking-luggage',
            self::Postponed => 'fa-calendar-xmark',
            self::Cancelled => 'fa-ban',
            self::Completed => 'fa-flag-checkered',
            self::ReportReceived => 'fa-file-circle-check',
            self::Closed => 'fa-lock',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // Draft → Approved is a decision recorded on the CEO's behalf (CD-03).
            self::Draft => [self::AwaitingDecision, self::Approved],
            self::AwaitingDecision => [self::Approved, self::Returned, self::Declined],
            self::Returned => [self::Draft, self::AwaitingDecision, self::Approved],
            self::Approved => [self::InProgress, self::Postponed, self::Cancelled, self::AwaitingDecision],
            self::InProgress => [self::Completed, self::Cancelled, self::AwaitingDecision],
            self::Postponed => [self::Approved, self::AwaitingDecision, self::Cancelled],
            self::Completed => [self::ReportReceived],
            self::ReportReceived => [self::Closed],
            self::Declined, self::Cancelled, self::Closed => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Details, participants and planned costs may be edited without an amendment. */
    public function isFreelyEditable(): bool
    {
        return in_array($this, [self::Draft, self::Returned], true);
    }

    /** Changes are allowed but recorded as amendments (PT-07, BR-10). */
    public function requiresAmendment(): bool
    {
        return in_array($this, [self::AwaitingDecision, self::Approved, self::InProgress, self::Postponed], true);
    }

    /** Read only (CF-10: closed records are never edited or deleted). */
    public function isReadOnly(): bool
    {
        return in_array($this, [self::Declined, self::Cancelled, self::Closed], true);
    }

    /** Has the CEO's approval (in or outside the system) and has not been stopped. */
    public function isApprovedOrLater(): bool
    {
        return in_array($this, [self::Approved, self::InProgress, self::Postponed, self::Completed, self::ReportReceived, self::Closed], true);
    }

    /** Execution has happened; attendance and actuals apply. */
    public function isDelivered(): bool
    {
        return in_array($this, [self::Completed, self::ReportReceived, self::Closed], true);
    }

    /** @return list<string> */
    public static function values(self ...$statuses): array
    {
        return array_map(fn (self $s) => $s->value, $statuses);
    }
}
