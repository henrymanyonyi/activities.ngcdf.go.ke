<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * FRD CB-06 / BR-09: money is exact. Amounts are carried as integer cents in
 * PHP and as DECIMAL(15,2) in the database; no float arithmetic anywhere.
 */
final class Money
{
    /** "1,234.5" / "1234.50" / 1234 / null → 123450 cents. Rejects anything with more than 2 decimals. */
    public static function toCents(string|int|float|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_int($value)) {
            return $value * 100;
        }

        // Floats only arrive from spreadsheet cells; format them before parsing so no float maths is done.
        $string = is_float($value) ? number_format($value, 2, '.', '') : str_replace([',', ' '], '', trim($value));

        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $string, $m)) {
            throw new InvalidArgumentException("\"{$value}\" is not a valid amount.");
        }

        $cents = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return $m[1] === '-' ? -$cents : $cents;
    }

    /** 123450 → "1234.50" (for storage). */
    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** 123450 → "1,234.50" (for display). */
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.number_format(intdiv($cents, 100)).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Divide, rounding half up to the cent. */
    public static function divide(int $cents, int $by): int
    {
        if ($by <= 0) {
            return 0;
        }

        return intdiv(2 * $cents + $by, 2 * $by);
    }

    /** $cents × quantity, where quantity may carry two decimals ("1.5" nights is not used, but hours might be). */
    public static function multiply(int $cents, string|int $quantity): int
    {
        $q = self::toCents((string) $quantity); // quantity in hundredths

        return intdiv($cents * $q + 50, 100);
    }

    /** Whole-number percentage change from $from to $to (null when $from is zero). */
    public static function percentChange(int $from, int $to): ?int
    {
        if ($from === 0) {
            return null;
        }

        return intdiv(($to - $from) * 100, $from);
    }
}
