<?php

namespace App\Services;

use App\Imports\ParticipantListSheet;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Office;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * FRD MD-01 / OI-12: import the HR staff list (Excel or CSV). The HR list is
 * authoritative, so an existing officer (matched by PF number) is updated.
 * Officers missing from the file are left as they are; deactivate them on screen.
 */
class StaffImporter
{
    public const COLUMNS = ['PF Number', 'Name', 'Designation', 'Job Grade', 'Department', 'Duty Station', 'Email', 'Phone', 'Active'];

    /**
     * @return array{created: int, updated: int, errors: list<string>}
     */
    public function import(string $path): array
    {
        try {
            $rows = Excel::toArray(new ParticipantListSheet, $path)[0] ?? [];
        } catch (Throwable) {
            return ['created' => 0, 'updated' => 0, 'errors' => ['The file could not be read. Upload Excel (.xlsx) or CSV.']];
        }

        if ($rows === [] || ! array_key_exists('pf_number', $rows[0]) || ! array_key_exists('name', $rows[0])) {
            return ['created' => 0, 'updated' => 0, 'errors' => ['The file needs at least the columns "PF Number" and "Name".']];
        }

        $errors = [];
        $valid = [];
        $seen = [];
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $pf = strtoupper(trim((string) ($row['pf_number'] ?? '')));
            $name = Department::normaliseName((string) ($row['name'] ?? ''));
            if ($pf === '' || $name === '') {
                $errors[] = "Row {$line}: PF Number and Name are required.";

                continue;
            }
            if (isset($seen[$pf])) {
                $errors[] = "Row {$line}: PF Number {$pf} also appears on row {$seen[$pf]}.";

                continue;
            }
            $email = trim((string) ($row['email'] ?? ''));
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Row {$line}: Email \"{$email}\" is not valid.";

                continue;
            }
            $seen[$pf] = $line;
            $valid[] = compact('pf', 'name', 'email') + ['row' => $row];
        }

        if ($errors !== []) {
            return ['created' => 0, 'updated' => 0, 'errors' => $errors];
        }

        $created = 0;
        $updated = 0;
        DB::transaction(function () use ($valid, &$created, &$updated) {
            foreach ($valid as $v) {
                $row = $v['row'];
                $active = strtolower(trim((string) ($row['active'] ?? 'yes')));
                $attributes = [
                    'name' => mb_substr($v['name'], 0, 255),
                    'designation_id' => Designation::resolveByName((string) ($row['designation'] ?? ''))?->id,
                    'job_grade' => mb_substr(strtoupper(trim((string) ($row['job_grade'] ?? ''))), 0, 20) ?: null,
                    'department_id' => Department::resolveByName((string) ($row['department'] ?? ''))?->id,
                    'office_id' => Office::resolveByName((string) ($row['duty_station'] ?? ''))?->id,
                    'email' => $v['email'] ?: null,
                    'phone' => mb_substr(trim((string) ($row['phone'] ?? '')), 0, 30) ?: null,
                    'is_active' => ! in_array($active, ['no', 'n', 'false', '0', 'inactive'], true),
                ];

                $staff = Staff::withTrashed()->where('staff_number', $v['pf'])->first();
                if ($staff) {
                    $staff->trashed() && $staff->restore();
                    $staff->update(array_filter($attributes, fn ($value) => $value !== null));
                    $updated++;
                } else {
                    Staff::create($attributes + ['staff_number' => $v['pf'], 'source' => 'import']);
                    $created++;
                }
            }
        });

        return ['created' => $created, 'updated' => $updated, 'errors' => []];
    }
}
