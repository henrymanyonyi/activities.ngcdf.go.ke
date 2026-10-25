<?php

namespace App\Support;

/** Kenyan mobile numbers in one form, +2547XXXXXXXX or +2541XXXXXXXX (as in NG-CDF Careers). */
final class MobileNumber
{
    public const PATTERN = '/^(?:\+?254|0)?([71]\d{8})$/';

    public static function normalise(?string $value): ?string
    {
        $digits = preg_replace('/[\s\-().]/', '', (string) $value);

        if (! preg_match(self::PATTERN, (string) $digits, $m)) {
            return null;
        }

        return '+254'.$m[1];
    }

    public static function isValid(?string $value): bool
    {
        return self::normalise($value) !== null;
    }

    /** +254712***678, for logs and screens that need not show the whole number. */
    public static function mask(string $value): string
    {
        return substr($value, 0, 7).'***'.substr($value, -3);
    }
}
