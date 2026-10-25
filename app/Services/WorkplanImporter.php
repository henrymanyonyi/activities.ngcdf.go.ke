<?php

namespace App\Services;

use App\Imports\StaffListSheet;
use App\Models\BudgetLine;
use App\Support\Money;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Reads a filled-in departmental workplan template (App\Exports\WorkplanTemplate):
 * one row per activity or vote. Every row is checked before anything is
 * saved, and every problem is returned with its row number; App\Services\Budgeting
 * saves the rows.
 */
class WorkplanImporter
{
    /** heading key => heading in the template */
    public const COLUMNS = [
        'code' => 'Code',
        'activity_or_budget_line' => 'Activity or budget line',
        'objective' => 'Objective',
        'deliverables' => 'Deliverables',
        'quantity' => 'Quantity',
        'unit_cost_kes' => 'Unit cost (KES)',
        'amount_kes' => 'Amount (KES)',
        'quarters' => 'Quarters',
        'responsible_officer' => 'Responsible officer',
    ];

    /**
     * @return array{rows: list<array{code: ?string, name: string, objective: ?string, deliverables: ?string, quantity: ?string, unit_cost: ?string, amount: ?string, quarters: string, responsible_officer: ?string}>, errors: list<string>}
     */
    public function parse(string $path, ?string $extension = null): array
    {
        // An upload's temporary file has no extension, so the format comes from the original name.
        $type = match (strtolower((string) $extension)) {
            'csv', 'txt' => ExcelFormat::CSV,
            'xls' => ExcelFormat::XLS,
            'xlsx' => ExcelFormat::XLSX,
            default => null,
        };

        try {
            $sheet = Excel::toArray(new StaffListSheet, $path, null, $type)[0] ?? [];
        } catch (Throwable) {
            return ['rows' => [], 'errors' => ['The file could not be read. Upload the workplan template as Excel (.xlsx) or CSV.']];
        }

        if ($sheet === [] || ! array_key_exists('activity_or_budget_line', $sheet[0])) {
            return ['rows' => [], 'errors' => ['Use the workplan template: it needs at least the column "Activity or budget line".']];
        }

        $rows = [];
        $errors = [];
        foreach ($sheet as $i => $row) {
            if (collect($row)->every(fn ($v) => trim((string) $v) === '')) {
                continue;
            }

            $line = $i + 2;
            $problems = [];
            $name = trim((string) ($row['activity_or_budget_line'] ?? ''));
            $quantity = $this->number($row['quantity'] ?? null);
            $unit = $this->number($row['unit_cost_kes'] ?? null);
            $amount = $this->number($row['amount_kes'] ?? null);
            $quarters = preg_split('/[\s,;]+/', strtoupper(trim((string) ($row['quarters'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY);

            if ($name === '') {
                $problems[] = 'Activity or budget line is required.';
            }
            foreach (['Quantity' => $quantity, 'Unit cost (KES)' => $unit, 'Amount (KES)' => $amount] as $label => $value) {
                if ($value === false) {
                    $problems[] = "{$label} is not a number.";
                }
            }
            if ($quantity !== false && $quantity !== null && (float) $quantity <= 0) {
                $problems[] = 'Quantity must be more than zero.';
            }
            $computed = $quantity && $unit ? (int) round((float) $quantity * Money::toCents($unit)) : 0;
            if ($amount !== false && ($amount === null ? $computed : Money::toCents($amount)) <= 0 && $quantity !== false && $unit !== false) {
                $problems[] = 'Give the amount, or the quantity and the unit cost.';
            }
            if ($bad = array_diff($quarters, array_keys(BudgetLine::QUARTERS))) {
                $problems[] = 'Quarters must be Q1, Q2, Q3 or Q4 (not '.implode(', ', $bad).').';
            }

            if ($problems !== []) {
                $errors[] = "Row {$line}: ".implode(' ', $problems);

                continue;
            }

            $rows[] = [
                'code' => mb_substr(trim((string) ($row['code'] ?? '')), 0, 40) ?: null,
                'name' => mb_substr($name, 0, 255),
                'objective' => trim((string) ($row['objective'] ?? '')) ?: null,
                'deliverables' => trim((string) ($row['deliverables'] ?? '')) ?: null,
                'quantity' => $quantity,
                'unit_cost' => $unit,
                'amount' => $amount,
                'quarters' => implode(',', $quarters),
                'responsible_officer' => mb_substr(trim((string) ($row['responsible_officer'] ?? '')), 0, 255) ?: null,
            ];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /** A number from a cell: null when empty, false when it is not a number. */
    private function number(mixed $value): string|false|null
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $text = str_replace([',', ' ', 'KES', 'Ksh'], '', trim((string) $value));
        if ($text === '') {
            return null;
        }

        return is_numeric($text) ? $text : false;
    }
}
