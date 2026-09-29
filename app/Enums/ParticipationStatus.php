<?php

namespace App\Enums;

enum ParticipationStatus: string
{
    case Nominated = 'nominated';
    case Confirmed = 'confirmed';
    case Attended = 'attended';
    case Absent = 'absent';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Nominated => 'Nominated',
            self::Confirmed => 'Confirmed',
            self::Attended => 'Attended',
            self::Absent => 'Did not attend',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Nominated => 'bg-slate-100 text-slate-500',
            self::Confirmed => 'bg-blue-50 text-blue-700',
            self::Attended => 'bg-emerald-50 text-emerald-700',
            self::Absent => 'bg-red-50 text-red-700',
            self::Withdrawn => 'bg-amber-50 text-amber-700',
        };
    }

    /** Attendance has been settled one way or the other; required before close-out. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Attended, self::Absent, self::Withdrawn], true);
    }

    /** @return list<string> */
    public static function values(self ...$statuses): array
    {
        return array_map(fn (self $s) => $s->value, $statuses);
    }

    /** Still expected to take part (before the activity is closed out). */
    public function isExpected(): bool
    {
        return in_array($this, [self::Nominated, self::Confirmed, self::Attended], true);
    }
}
