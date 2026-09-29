<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Reads the first sheet of an uploaded participant list as heading-keyed rows
 * (headings are slugged: "Staff Number" becomes staff_number). Parsing and
 * validation live in App\Services\ParticipantListImporter.
 */
class ParticipantListSheet implements SkipsEmptyRows, WithCalculatedFormulas, WithHeadingRow {}
