<?php

namespace App\Exports;

use App\Models\User;
use App\Reports\Report;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Excel output of a standard report. Carries the RESTRICTED marking, the
 * user's name and the time it was produced at top and bottom (FRD CF-07).
 */
class ReportExport implements FromArray, ShouldAutoSize, WithEvents, WithTitle
{
    private const MARK = 'RESTRICTED: FOR THE OFFICE OF THE CEO ONLY';

    private int $headingRow = 6;

    public function __construct(private Report $report, private array $filters, private Collection $rows, private User $user) {}

    public function title(): string
    {
        return substr($this->report->title(), 0, 31);
    }

    /** @return list<list<mixed>> */
    public function array(): array
    {
        $columns = $this->report->columns();
        $stamp = 'Produced by '.$this->user->name.' ('.$this->user->roleLabel().') on '.now()->format('d M Y H:i');

        $out = [
            [self::MARK],
            ['NG-CDF Board · Office of the CEO / Accounting Officer'],
            [$this->report->title()],
            [$this->report->subtitle($this->filters)],
            [$stamp],
            array_values(array_map(fn ($c) => $c[0], $columns)),
        ];

        foreach ($this->rows as $row) {
            $out[] = collect($columns)->map(fn ($c, $key) => $this->cell($row[$key] ?? null, $c[1]))->values()->all();
        }

        $totals = $this->report->totals($this->rows);
        if ($totals !== [] && $this->rows->isNotEmpty()) {
            $out[] = collect($columns)->keys()->map(fn ($key, $i) => $i === 0 ? 'Total' : (isset($totals[$key]) ? $this->cell($totals[$key], 'money') : null))->all();
        }

        $out[] = [];
        $out[] = [self::MARK.' · '.$stamp];

        return $out;
    }

    /** @return array<class-string, callable> */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->getStyle('A1')->getFont()->setBold(true)->getColor()->setRGB('B91C1C');
                $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(13);
                $sheet->getStyle($this->headingRow.':'.$this->headingRow)->getFont()->setBold(true);
                $sheet->freezePane('A'.($this->headingRow + 1));

                $letters = range('A', 'Z');
                foreach (array_values($this->report->columns()) as $i => $column) {
                    if ($column[1] === 'money' && isset($letters[$i])) {
                        $sheet->getStyle($letters[$i].($this->headingRow + 1).':'.$letters[$i].($this->headingRow + $this->rows->count() + 1))
                            ->getNumberFormat()->setFormatCode('#,##0.00');
                    }
                }
            },
        ];
    }

    private function cell(mixed $value, string $type): mixed
    {
        return match (true) {
            $value === null => null,
            // Presentation only: the exact cent value becomes a spreadsheet number.
            $type === 'money' => (float) Money::fromCents((int) $value),
            $value instanceof Carbon => $value->format('d M Y'),
            default => $value,
        };
    }
}
