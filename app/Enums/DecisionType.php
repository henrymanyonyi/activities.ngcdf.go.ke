<?php

namespace App\Enums;

/** FRD CD-02. */
enum DecisionType: string
{
    case Approved = 'approved';
    case Declined = 'declined';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::Declined => 'Declined',
            self::Returned => 'Returned with directive',
        };
    }

    /** The verb on the button. */
    public function actionLabel(): string
    {
        return match ($this) {
            self::Approved => 'Approve',
            self::Declined => 'Decline',
            self::Returned => 'Return with directive',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Approved => 'bg-emerald-50 text-emerald-700',
            self::Declined => 'bg-red-50 text-red-700',
            self::Returned => 'bg-orange-50 text-orange-700',
        };
    }

    public function status(): ActivityStatus
    {
        return match ($this) {
            self::Approved => ActivityStatus::Approved,
            self::Declined => ActivityStatus::Declined,
            self::Returned => ActivityStatus::Returned,
        };
    }

    public function requiresComment(): bool
    {
        return $this !== self::Approved;
    }
}
