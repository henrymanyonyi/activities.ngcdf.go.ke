<?php

namespace App\Enums;

/** Whether a cost category is normally incurred per person (DSA) or once for the activity (venue). */
enum CostScope: string
{
    case Participant = 'participant';
    case Activity = 'activity';

    public function label(): string
    {
        return match ($this) {
            self::Participant => 'Per participant',
            self::Activity => 'Shared (whole activity)',
        };
    }
}
