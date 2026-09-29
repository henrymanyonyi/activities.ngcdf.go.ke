<?php

namespace App\Reports;

use App\Support\Money;
use App\Support\Period;
use App\Support\Ui;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One standard report (FRD Section 10). Columns and rows are defined once
 * and drive the on-screen table, Excel, PDF and print output alike.
 * Money cells are integer cents; date cells are Carbon.
 */
abstract class Report
{
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    public function icon(): string
    {
        return 'fa-file-lines';
    }

    /** Permission needed to view the report on screen. */
    public function permission(): string
    {
        return 'activities.view';
    }

    /** Filter controls to show: any of period, department. */
    public function filters(): array
    {
        return ['period'];
    }

    /** @return array<string, array{0: string, 1: 'text'|'money'|'date'|'int'|'percent'}> key => [label, type] */
    abstract public function columns(): array;

    /** @return Collection<int, array<string, mixed>> */
    abstract public function rows(array $filters): Collection;

    /** @return array<string, int> money column totals for the footer, where meaningful */
    public function totals(Collection $rows): array
    {
        return collect($this->columns())
            ->filter(fn ($c) => $c[1] === 'money')
            ->map(fn ($c, $key) => (int) $rows->sum($key))
            ->all();
    }

    public function subtitle(array $filters): string
    {
        return in_array('period', $this->filters(), true) ? Period::fromFilters($filters)->label : '';
    }

    public function format(mixed $value, string $type): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            $type === 'money' => Money::format((int) $value),
            $type === 'date' && $value instanceof Carbon => Ui::date($value),
            $type === 'percent' => $value.'%',
            $type === 'int' => number_format((int) $value),
            default => (string) $value,
        };
    }
}
