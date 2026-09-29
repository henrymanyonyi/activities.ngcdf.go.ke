<?php

namespace App\Enums;

/** Who a non-staff participant is (Q4: external participants are recorded and optionally counted per head). */
enum ExternalCategory: string
{
    case Mp = 'mp';
    case NgcdfcMember = 'ngcdfc_member';
    case BoardMember = 'board_member';
    case GovernmentOfficer = 'government_officer';
    case Partner = 'partner';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Mp => 'Member of Parliament',
            self::NgcdfcMember => 'NGCDFC member',
            self::BoardMember => 'Board member',
            self::GovernmentOfficer => 'Government officer',
            self::Partner => 'Partner / stakeholder',
            self::Other => 'Other',
        };
    }

    /** Accepts the value or label, any case. Blank means Other. */
    public static function fromInput(?string $value): ?self
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return self::Other;
        }

        foreach (self::cases() as $case) {
            if ($case->value === $value || strtolower($case->label()) === $value || str_replace('_', ' ', $case->value) === $value) {
                return $case;
            }
        }

        return null;
    }
}
