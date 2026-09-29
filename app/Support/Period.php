<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * A reporting window chosen on screen: a financial year, optionally one
 * quarter of it, or a custom date range (FRD MD-07, SR-01).
 */
final class Period
{
    public function __construct(public readonly Carbon $from, public readonly Carbon $to, public readonly string $label) {}

    /** @param  array{fy?: ?string, quarter?: int|string|null, from?: ?string, to?: ?string}  $filters */
    public static function fromFilters(array $filters): self
    {
        if (! empty($filters['from']) || ! empty($filters['to'])) {
            $from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : Carbon::create(2000);
            $to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : Carbon::create(2100);

            return new self($from, $to, Ui::date(! empty($filters['from']) ? $from : null).' to '.Ui::date(! empty($filters['to']) ? $to : null));
        }

        $fy = ! empty($filters['fy']) ? FinancialYear::fromLabel($filters['fy']) : FinancialYear::current();

        if (! empty($filters['quarter'])) {
            [$from, $to] = $fy->quarter((int) $filters['quarter']);

            return new self($from, $to, "FY {$fy->label()} Q{$filters['quarter']}");
        }

        return new self($fy->start(), $fy->end(), "FY {$fy->label()}");
    }
}
