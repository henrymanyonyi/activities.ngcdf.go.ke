<?php

namespace App\Enums;

/** FRD PT-02. */
enum ParticipantRole: string
{
    case Lead = 'lead';
    case Member = 'member';
    case Driver = 'driver';
    case Support = 'support';

    public function label(): string
    {
        return match ($this) {
            self::Lead => 'Team lead',
            self::Member => 'Member',
            self::Driver => 'Driver',
            self::Support => 'Support staff',
        };
    }

    /** Accepts the value or label, any case, as typed in an uploaded list. Blank means Member. */
    public static function fromInput(?string $value): ?self
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return self::Member;
        }

        foreach (self::cases() as $case) {
            if ($case->value === $value || strtolower($case->label()) === $value) {
                return $case;
            }
        }

        return match ($value) {
            'team leader', 'leader', 'head' => self::Lead,
            'participant', 'officer' => self::Member,
            'support' => self::Support,
            default => null,
        };
    }
}
