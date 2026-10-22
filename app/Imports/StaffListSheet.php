<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Reads the first sheet of an HR staff list as heading-keyed rows ("PF Number"
 * becomes pf_number). Blank rows are kept, so each row's position is its line
 * in the file; App\Services\StaffImporter passes over them.
 */
class StaffListSheet implements WithCalculatedFormulas, WithHeadingRow {}
