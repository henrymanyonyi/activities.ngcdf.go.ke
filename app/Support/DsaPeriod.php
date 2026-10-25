<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The one rule for an activity's days and payable DSA nights (business owner,
 * 25 October 2026). Every calculation and display uses it:
 *
 *  - Activity days: the start and end dates, both counted (26 Oct to 4 Nov = 10).
 *  - An overnight activity (two days or more) pays the departure night, the
 *    night before the start date, and every night up to and including the
 *    night of the end date: 26 Oct to 4 Nov pays 11 nights (25 Oct to 4 Nov).
 *  - A same-day activity (one day) pays no night.
 *  - With the "days" basis in Settings (OI-01), DSA is paid per activity day instead.
 *
 * Officers on only part of an activity follow the same rule on their own days.
 */
final class DsaPeriod
{
    public const BASIS_NIGHTS = 'nights';

    public const BASIS_DAYS = 'days';

    private function __construct(public readonly Carbon $start, public readonly int $days) {}

    public static function of(Carbon $start, int $days): self
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('An activity lasts at least one day.');
        }

        return new self($start->copy()->startOfDay(), $days);
    }

    public static function between(Carbon $start, Carbon $end): self
    {
        if ($end->copy()->startOfDay()->lt($start->copy()->startOfDay())) {
            throw new \InvalidArgumentException('The end date is before the start date.');
        }

        return self::of($start, (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1);
    }

    /** Payable nights for an activity of this many days, without dates. */
    public static function nightsFor(int $days): int
    {
        return $days >= 2 ? $days + 1 : 0;
    }

    public function end(): Carbon
    {
        return $this->start->copy()->addDays($this->days - 1);
    }

    public function isOvernight(): bool
    {
        return $this->days >= 2;
    }

    /** The day the team travels out: the day before the start, for an overnight activity. */
    public function departure(): ?Carbon
    {
        return $this->isOvernight() ? $this->start->copy()->subDay() : null;
    }

    public function nights(): int
    {
        return self::nightsFor($this->days);
    }

    /** DSA units: payable nights, or activity days on the "days" basis. */
    public function units(string $basis = self::BASIS_NIGHTS): int
    {
        return $basis === self::BASIS_DAYS ? $this->days : $this->nights();
    }

    /** "10 days · 11 nights (departs 25 Oct 2026)", for forms and pages. */
    public function summary(): string
    {
        $nights = $this->nights();

        return $this->days.' '.($this->days === 1 ? 'day' : 'days').' · '.$nights.' '.($nights === 1 ? 'night' : 'nights')
            .($this->departure() ? ' (departs '.$this->departure()->format('d M Y').')' : ' (same day)');
    }
}
