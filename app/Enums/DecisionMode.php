<?php

namespace App\Enums;

/** FRD CD-02 / CD-03: decided by the CEO in the system, or recorded on the CEO's behalf. */
enum DecisionMode: string
{
    case InSystem = 'in_system';
    case RecordedOnBehalf = 'recorded_on_behalf';

    public function label(): string
    {
        return match ($this) {
            self::InSystem => 'Decided by the CEO in the system',
            self::RecordedOnBehalf => 'Recorded on the CEO\'s behalf',
        };
    }
}
