<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Kenyan government financial year, 1 July to 30 June (FRD MD-07). Quarters:
 * Q1 Jul–Sep, Q2 Oct–Dec, Q3 Jan–Mar, Q4 Apr–Jun.
 */
final class FinancialYear
{
    private function __construct(public readonly int $startYear) {}

    public static function for(Carbon|string $date): self
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        return new self($date->month >= 7 ? $date->year : $date->year - 1);
    }

    public static function current(): self
    {
        return self::for(today());
    }

    /** Parse "2026/27" or "2026/2027". */
    public static function fromLabel(string $label): self
    {
        return new self((int) substr(trim($label), 0, 4));
    }

    public function label(): string
    {
        return sprintf('%d/%02d', $this->startYear, ($this->startYear + 1) % 100);
    }

    /** "2026-27", safe in references and file names. */
    public function shortLabel(): string
    {
        return str_replace('/', '-', $this->label());
    }

    public function start(): Carbon
    {
        return Carbon::create($this->startYear, 7, 1)->startOfDay();
    }

    public function end(): Carbon
    {
        return Carbon::create($this->startYear + 1, 6, 30)->endOfDay();
    }

    public static function quarterOf(Carbon $date): int
    {
        return match (true) {
            $date->month >= 7 && $date->month <= 9 => 1,
            $date->month >= 10 => 2,
            $date->month <= 3 => 3,
            default => 4,
        };
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function quarter(int $quarter): array
    {
        $start = $this->start()->copy()->addMonths(($quarter - 1) * 3);

        return [$start, $start->copy()->addMonths(3)->subDay()->endOfDay()];
    }

    /** @return Collection<int, string> recent years, newest first, for filters */
    public static function options(int $back = 4, int $ahead = 1): Collection
    {
        $current = self::current()->startYear;

        return collect(range($current + $ahead, $current - $back))
            ->mapWithKeys(fn (int $year) => [(new self($year))->label() => (new self($year))->label()]);
    }
}
