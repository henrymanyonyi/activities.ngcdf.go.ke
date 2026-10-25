<?php

namespace App\Exports;

use App\Services\WorkplanImporter;
use App\Support\DocumentFont;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithDefaultStyles;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The departmental workplan and budget template (App\Services\WorkplanImporter reads it back), with two
 * example rows from an ICT workplan. A filled-in copy for ICT is in docs/guides.
 */
class WorkplanTemplate implements FromArray, ShouldAutoSize, WithDefaultStyles, WithHeadings, WithStyles
{
    /** @return array<string, mixed> */
    public function defaultStyles(Style $defaultStyle): array
    {
        return ['font' => ['name' => DocumentFont::NAME]];
    }

    /** @return list<string> */
    public function headings(): array
    {
        return array_values(WorkplanImporter::COLUMNS);
    }

    /** @return list<list<string|int|float>> */
    public function array(): array
    {
        return [
            ['2211300', 'Network support visits to constituency offices', 'Keep every constituency office connected', 'Visit report for each office; faults closed', 24, 35000, '', 'Q1,Q2,Q3,Q4', 'Head of Network Services'],
            ['3111002', 'Replace end-of-life laptops', 'Equip officers with working computers', '40 laptops issued and asset-tagged', 40, 95000, '', 'Q2', 'ICT Officer, Hardware'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
