<?php

namespace App\Enums;

use App\Models\User;

/** Which office holds a memo that is Under review. Values match the role names. */
enum ReviewOffice: string
{
    case AssistantChiefOfStaff = 'assistant_chief_of_staff';
    case ChiefOfStaff = 'chief_of_staff';

    public function label(): string
    {
        return User::ROLES[$this->value];
    }

    /** Short form for tight spaces. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::AssistantChiefOfStaff => 'ACOS',
            self::ChiefOfStaff => 'COS',
        };
    }

    public function role(): string
    {
        return $this->value;
    }

    /** The office whose holder this user is, if any. */
    public static function forUser(User $user): ?self
    {
        return match (true) {
            $user->hasRole(User::ROLE_CHIEF_OF_STAFF) => self::ChiefOfStaff,
            $user->hasRole(User::ROLE_ASSISTANT_CHIEF_OF_STAFF) => self::AssistantChiefOfStaff,
            default => null,
        };
    }
}
