<?php

namespace App\Enums;

/** FRD FR-03 / OI-06. */
enum ImprestStatus: string
{
    case NotApplicable = 'not_applicable';
    case Issued = 'issued';
    case Outstanding = 'outstanding';
    case Surrendered = 'surrendered';

    public function label(): string
    {
        return match ($this) {
            self::NotApplicable => 'Not applicable',
            self::Issued => 'Issued',
            self::Outstanding => 'Surrender outstanding',
            self::Surrendered => 'Surrendered',
        };
    }
}
