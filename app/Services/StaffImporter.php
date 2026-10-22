<?php

namespace App\Services;

use App\Enums\EmploymentType;
use App\Imports\StaffListSheet;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Office;
use App\Models\Staff;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * FRD MD-01 / OI-12: import the HR staff list (Excel or CSV). The HR list is
 * authoritative, so an existing officer (matched by PF number) is updated.
 * Officers missing from the file are left as they are; deactivate them on screen.
 *
 * The list covers permanent staff, casuals and attachees (Employment Type;
 * blank means Permanent). Casuals and attachees may have no PF number: they
 * are matched by exact name among officers without one, and a name that
 * matches more than one is rejected. Department and Duty Station must already
 * be on the reference lists, so a misspelling cannot add a department.
 * Every row is checked before anything is saved; with $dryRun nothing is saved.
 *
 * A Head of Department may import too, under the same rules as adding one
 * officer (App\Services\StaffRecords): new officers are added as theirs,
 * officers they added before are updated, everyone else is left untouched
 * and counted as skipped, and the Active column is ignored (activation is the
 * Office of the CEO's decision).
 */
class StaffImporter
{
    public const COLUMNS = ['PF Number', 'Name', 'Employment Type', 'Designation', 'Job Grade', 'Department', 'Duty Station', 'Email', 'Phone', 'Date Joined', 'Contract End Date', 'Active'];

    /**
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function import(string $path, ?User $user = null, bool $dryRun = false): array
    {
        $office = $user === null || $user->can('reference.manage');
        if (! $office && ! $user->can('staff.add')) {
            throw new AuthorizationException('You are not permitted to import the staff list.');
        }

        try {
            $rows = Excel::toArray(new StaffListSheet, $path)[0] ?? [];
        } catch (Throwable) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['The file could not be read. Upload Excel (.xlsx) or CSV.']];
        }

        if ($rows === [] || ! array_key_exists('pf_number', $rows[0]) || ! array_key_exists('name', $rows[0])) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['The file needs at least the columns "PF Number" and "Name".']];
        }

        $departments = Department::query()->get(['id', 'name'])->mapWithKeys(fn ($d) => [mb_strtolower($d->name) => $d->id]);
        $offices = Office::query()->get(['id', 'name'])->mapWithKeys(fn ($o) => [mb_strtolower($o->name) => $o->id]);

        $errors = [];
        $valid = [];
        $seen = [];
        $seenNames = [];
        foreach ($rows as $i => $row) {
            if (collect($row)->every(fn ($value) => trim((string) $value) === '')) {
                continue;
            }

            $line = $i + 2;
            $pf = $this->staffNumber($row['pf_number'] ?? null);
            $name = Department::normaliseName((string) ($row['name'] ?? ''));
            $typeText = trim((string) ($row['employment_type'] ?? ''));
            $type = $typeText === '' ? null : EmploymentType::fromLabel($typeText);
            $rowErrors = [];

            if ($typeText !== '' && $type === null) {
                $rowErrors[] = "Employment Type \"{$typeText}\" is not Permanent, Casual or Attachee.";
            }
            if ($name === '') {
                $rowErrors[] = 'Name is required.';
            }
            $nameKey = mb_strtolower($name);
            $existing = null;
            if ($pf === '' && $name !== '') {
                $matches = Staff::withTrashed()->whereNull('staff_number')->whereRaw('LOWER(name) = ?', [$nameKey])->get();
                if ($matches->count() > 1) {
                    $rowErrors[] = "{$name} has no PF Number and matches {$matches->count()} officers on the list; add the PF Number or correct the list on screen.";
                }
                $existing = $matches->first();
            }
            // A blank Employment Type keeps the type of the officer being updated.
            if ($pf === '' && ($type ?? $existing?->employment_type ?? EmploymentType::Permanent)->requiresStaffNumber()) {
                $rowErrors[] = 'PF Number is required for permanent staff.';
            }
            if ($pf !== '' && isset($seen[$pf])) {
                $rowErrors[] = "PF Number {$pf} also appears on row {$seen[$pf]}.";
            }
            if ($pf === '' && $name !== '' && isset($seenNames[$nameKey])) {
                $rowErrors[] = "{$name} has no PF Number and also appears on row {$seenNames[$nameKey]}.";
            }

            $email = trim((string) ($row['email'] ?? ''));
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $rowErrors[] = "Email \"{$email}\" is not valid.";
            }

            $department = Department::normaliseName((string) ($row['department'] ?? ''));
            if ($department !== '' && ! $departments->has(mb_strtolower($department))) {
                $rowErrors[] = "Department \"{$department}\" is not on the list of departments.";
            }
            $station = Department::normaliseName((string) ($row['duty_station'] ?? ''));
            if ($station !== '' && ! $offices->has(mb_strtolower($station))) {
                $rowErrors[] = "Duty Station \"{$station}\" is not on the list of duty stations.";
            }

            $joined = $this->date($row['date_joined'] ?? null);
            $ends = $this->date($row['contract_end_date'] ?? null);
            if ($joined === false) {
                $rowErrors[] = 'Date Joined "'.trim((string) $row['date_joined']).'" is not a date (use YYYY-MM-DD).';
            }
            if ($ends === false) {
                $rowErrors[] = 'Contract End Date "'.trim((string) $row['contract_end_date']).'" is not a date (use YYYY-MM-DD).';
            }
            if ($joined && $ends && $ends->lt($joined)) {
                $rowErrors[] = 'Contract End Date is before Date Joined.';
            }

            if ($rowErrors !== []) {
                $errors[] = "Row {$line}: ".implode(' ', $rowErrors);

                continue;
            }

            if ($pf !== '') {
                $seen[$pf] = $line;
                $existing = Staff::withTrashed()->where('staff_number', $pf)->first();
            } else {
                $seenNames[$nameKey] = $line;
            }

            $valid[] = [
                'existing' => $existing,
                'pf' => $pf,
                'row' => $row,
                'attributes' => [
                    'name' => mb_substr($name, 0, 255),
                    'employment_type' => $type,
                    'designation' => (string) ($row['designation'] ?? ''),
                    'job_grade' => mb_substr(strtoupper(trim((string) ($row['job_grade'] ?? ''))), 0, 20) ?: null,
                    'department_id' => $departments->get(mb_strtolower($department)),
                    'office_id' => $offices->get(mb_strtolower($station)),
                    'email' => $email ?: null,
                    'phone' => mb_substr(trim((string) ($row['phone'] ?? '')), 0, 30) ?: null,
                    'joined_on' => $joined?->toDateString(),
                    'contract_ends_on' => $ends?->toDateString(),
                ],
            ];
        }

        if ($errors !== []) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => $errors];
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $save = function () use ($valid, $office, $user, &$created, &$updated, &$skipped) {
            foreach ($valid as $v) {
                $active = strtolower(trim((string) ($v['row']['active'] ?? 'yes')));
                $attributes = $v['attributes'];
                $attributes['designation_id'] = Designation::resolveByName($attributes['designation'])?->id;
                $attributes['is_active'] = ! in_array($active, ['no', 'n', 'false', '0', 'inactive'], true);
                unset($attributes['designation']);

                if (! $office) {
                    unset($attributes['is_active']);
                }

                $staff = $v['existing'];
                if ($staff && ! $office && ($staff->trashed() || (int) $staff->created_by !== $user->id)) {
                    $skipped++;
                } elseif ($staff) {
                    $staff->trashed() && $staff->restore();
                    $staff->update(array_filter($attributes, fn ($value) => $value !== null));
                    $updated++;
                } else {
                    $attributes = array_filter($attributes, fn ($value) => $value !== null);
                    Staff::create($attributes + ($office
                        ? ['staff_number' => $v['pf'] ?: null, 'source' => 'import']
                        : ['staff_number' => $v['pf'] ?: null, 'source' => 'department', 'is_active' => true, 'joined_on' => today(), 'created_by' => $user->id]));
                    $created++;
                }
            }
        };

        if ($dryRun) {
            DB::beginTransaction();
            try {
                $save();
            } finally {
                DB::rollBack();
            }
        } else {
            DB::transaction($save);
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'errors' => []];
    }

    /** PF numbers are text; one typed into a number cell comes back as a number and loses only its formatting. */
    private function staffNumber(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            $value = sprintf('%.0f', $value);
        }

        return strtoupper(trim((string) $value));
    }

    /**
     * An Excel date cell, or text as YYYY-MM-DD, DD/MM/YYYY or "7 June 2016".
     * Null when blank, false when it is not a date.
     */
    private function date(mixed $value): CarbonImmutable|false|null
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $value > 0 ? CarbonImmutable::instance(ExcelDate::excelToDateTimeObject($value))->startOfDay() : false;
        }

        $text = preg_replace('/(\d)(st|nd|rd|th)\b/i', '$1', Department::normaliseName((string) $value));
        foreach (['Y-m-d', 'd/m/Y', 'j/n/Y', 'd F Y', 'j F Y', 'd M Y', 'j M Y', 'd-M-Y', 'j-M-Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $text);
            } catch (Throwable) {
                continue;
            }

            // The round trip rejects dates PHP would roll over, such as 31/02/2026.
            if ($date && strcasecmp($date->format($format), $text) === 0) {
                return $date;
            }
        }

        return false;
    }
}
