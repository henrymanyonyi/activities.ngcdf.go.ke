<?php

namespace App\Enums;

/**
 * A department budget or a budget adjustment (business owner, 21 October
 * 2026). Prepared as a draft by the CEO, the Chief of Staff or the Assistant;
 * only the CEO approves; the CEO can return a submitted one for correction.
 * Changes only through App\Services\Budgeting.
 */
enum BudgetStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Awaiting CEO approval',
            self::Returned => 'Returned for correction',
            self::Approved => 'Approved',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-100 text-slate-500',
            self::Submitted => 'bg-amber-50 text-amber-700',
            self::Returned => 'bg-orange-50 text-orange-700',
            self::Approved => 'bg-emerald-50 text-emerald-700',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Draft => 'fa-pen-ruler',
            self::Submitted => 'fa-hourglass-half',
            self::Returned => 'fa-reply',
            self::Approved => 'fa-circle-check',
        };
    }

    /** Draft or returned: the preparer can still change it. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Returned], true);
    }
}
