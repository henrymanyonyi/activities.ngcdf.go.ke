<?php

namespace App\Enums;

/** Terms an officer is engaged on (HR register, October 2026). Only permanent staff must have a PF number. */
enum EmploymentType: string
{
    case Permanent = 'permanent';
    case Casual = 'casual';
    case Attachee = 'attachee';

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Permanent',
            self::Casual => 'Casual',
            self::Attachee => 'Attachee',
        };
    }

    public function requiresStaffNumber(): bool
    {
        return $this === self::Permanent;
    }

    /** Reads the Employment Type column of an HR list; "Temporary" is the register's word for a casual. */
    public static function fromLabel(string $label): ?self
    {
        return match (strtolower(trim($label))) {
            'permanent' => self::Permanent,
            'casual', 'temporary', 'temporary/casual' => self::Casual,
            'attachee' => self::Attachee,
            default => null,
        };
    }
}
