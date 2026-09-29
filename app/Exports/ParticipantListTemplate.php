<?php

namespace App\Exports;

use App\Services\ParticipantListImporter;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** The published participant-list format, with two example rows (one staff, one external). */
class ParticipantListTemplate implements FromArray, ShouldAutoSize, WithHeadings, WithStyles
{
    /** @return list<string> */
    public function headings(): array
    {
        return array_values(ParticipantListImporter::COLUMNS);
    }

    /** @return list<list<string|int>> */
    public function array(): array
    {
        return [
            ['NGCDF/0123', 'Jane Wanjiru', 'Projects, Planning & M&E', 'Senior Officer', 'NGCDF 5', 'Headquarters', 'Nairobi Region', 'Team lead', 3, 'No', '', '', 'jane@example.go.ke', '0700000000'],
            ['', 'Hon. John Doe', '', '', '', '', '', 'Member', 2, 'Yes', 'National Assembly', 'Member of Parliament', '', ''],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
