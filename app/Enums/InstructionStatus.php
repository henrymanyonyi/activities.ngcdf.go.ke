<?php

namespace App\Enums;

/** Progress of an action instruction: sent by the CEO, acknowledged and completed by the department. */
enum InstructionStatus: string
{
    case Sent = 'sent';
    case Acknowledged = 'acknowledged';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Sent',
            self::Acknowledged => 'Acknowledged',
            self::Completed => 'Completed',
        };
    }

    /** PAKA-RANGI §3.4: amber pending, blue in hand, emerald done. */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Sent => 'bg-amber-50 text-amber-700',
            self::Acknowledged => 'bg-blue-50 text-blue-700',
            self::Completed => 'bg-emerald-50 text-emerald-700',
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::Completed;
    }
}
