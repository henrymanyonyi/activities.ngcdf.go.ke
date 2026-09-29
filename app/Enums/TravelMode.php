<?php

namespace App\Enums;

/** FRD MD-06. */
enum TravelMode: string
{
    case Air = 'air';
    case RoadBoardVehicle = 'road_board';
    case RoadPublic = 'road_public';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Air => 'Air',
            self::RoadBoardVehicle => 'Road (Board vehicle)',
            self::RoadPublic => 'Road (public or reimbursable)',
            self::None => 'None',
        };
    }
}
