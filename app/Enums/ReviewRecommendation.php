<?php

namespace App\Enums;

/** A reviewer's recommendation to the CEO, recorded with a review note. */
enum ReviewRecommendation: string
{
    case Approve = 'approve';
    case Revise = 'revise';
    case Decline = 'decline';

    public function label(): string
    {
        return match ($this) {
            self::Approve => 'Recommend approval',
            self::Revise => 'Recommend changes',
            self::Decline => 'Do not recommend',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Approve => 'bg-emerald-50 text-emerald-700',
            self::Revise => 'bg-amber-50 text-amber-700',
            self::Decline => 'bg-red-50 text-red-700',
        };
    }
}
