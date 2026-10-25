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

    /**
     * The DSA grade an officer on these terms is costed at (25 October 2026): every casual is on scale 10
     * (KES 6,300), an attachee without a grade on INTERN, and permanent staff on their own scale.
     */
    public function defaultDsaGrade(): ?string
    {
        return match ($this) {
            self::Casual => self::CASUAL_SCALE,
            self::Attachee => 'INTERN',
            self::Permanent => null,
        };
    }

    /** Whether the officer must be on defaultDsaGrade() whatever grade is typed: casuals are always scale 10. */
    public function hasFixedDsaGrade(): bool
    {
        return $this === self::Casual;
    }

    public const CASUAL_SCALE = '10';

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
