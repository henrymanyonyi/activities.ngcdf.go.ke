<?php

namespace App\Enums;

/** FRD DB-06: green (On track), amber (Needs attention), red (At risk). */
enum Rag: string
{
    case Green = 'green';
    case Amber = 'amber';
    case Red = 'red';

    public function label(): string
    {
        return match ($this) {
            self::Green => 'On track',
            self::Amber => 'Needs attention',
            self::Red => 'At risk',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Green => 'bg-emerald-50 text-emerald-700',
            self::Amber => 'bg-amber-50 text-amber-700',
            self::Red => 'bg-red-50 text-red-700',
        };
    }

    public function dotClasses(): string
    {
        return match ($this) {
            self::Green => 'bg-emerald-500',
            self::Amber => 'bg-amber-500',
            self::Red => 'bg-red-500',
        };
    }
}
