<?php

namespace App\Services;

use App\Enums\EmploymentType;
use App\Imports\StaffListSheet;
use App\Models\Department;
use App\Models\Designation;
use App\Models\DsaRate;
use App\Models\Office;
use App\Models\Staff;
use App\Models\User;
use App\Support\MobileNumber;
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
 * Job Grade is the scale from the HR register (1 to 10; a "Job Scale" column
 * is read too), or INTERN, which only attachees may have; an attachee without
 * a grade gets INTERN. Every casual is on scale 10 (25 October 2026): a blank,
 * INTERN or 10 is recorded as 10, and any other scale is rejected.
 * Email and Phone are the officer's work contacts: an account made from the
 * officer picks them up, and the mobile number gets the action-needed SMS, so
 * Phone must be a Kenyan mobile and is kept as +2547XXXXXXXX. A file with only
 * PF Number, Name, Email and Phone updates contacts and nothing else: a blank
 * cell never erases a value, and a blank Active keeps the officer's status.
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
        $grades = DsaRate::grades();

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
            $existing = $pf !== '' ? Staff::withTrashed()->where('staff_number', $pf)->first() : null;
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

            // DSA classification: a numbered grade or INTERN. Every casual is on scale 10; an attachee
            // without one is costed at INTERN, and one HR has reclassified keeps their grade when the cell is blank.
            $effectiveType = $type ?? $existing?->employment_type ?? EmploymentType::Permanent;
            $gradeText = trim((string) ($row['job_grade'] ?? $row['job_scale'] ?? ''));
            $grade = $gradeText === '' ? null : DsaRate::normaliseGrade($gradeText, $grades);
            if ($gradeText !== '' && $grade === null) {
                $rowErrors[] = "Job Grade \"{$gradeText}\" is not a scale from 1 to 10, or INTERN for attachees.";
            } elseif ($effectiveType->hasFixedDsaGrade()) {
                if ($grade !== null && $grade !== DsaRate::INTERN && $grade !== $effectiveType->defaultDsaGrade()) {
                    $rowErrors[] = "Job Grade \"{$gradeText}\": casuals are on DSA scale 10.";
                }
                $grade = $effectiveType->defaultDsaGrade();
            } elseif ($grade === DsaRate::INTERN && $effectiveType !== EmploymentType::Attachee) {
                $rowErrors[] = 'Job Grade INTERN is only for attachees.';
            } elseif ($grade === null && $effectiveType->defaultDsaGrade() !== null && blank($existing?->job_grade)) {
                $grade = $effectiveType->defaultDsaGrade();
            }

            $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $rowErrors[] = "Email \"{$email}\" is not valid.";
            }

            // Mobile numbers receive the action-needed SMS, so they are kept in one form, +2547XXXXXXXX.
            $phoneText = $this->phoneText($row['phone'] ?? null);
            $phone = MobileNumber::normalise($phoneText);
            if ($phoneText !== '' && $phone === null) {
                $rowErrors[] = "Phone \"{$phoneText}\" is not a Kenyan mobile number (e.g. 0712 345 678 or +254 712 345 678).";
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
                    'job_grade' => $grade,
                    'department_id' => $departments->get(mb_strtolower($department)),
                    'office_id' => $offices->get(mb_strtolower($station)),
                    'email' => $email ?: null,
                    'phone' => $phone,
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
                // A blank Active cell keeps an officer's status (a contacts-only file never reactivates a leaver); a new officer is active.
                $active = strtolower(trim((string) ($v['row']['active'] ?? '')));
                $attributes = $v['attributes'];
                $attributes['designation_id'] = Designation::resolveByName($attributes['designation'])?->id;
                $attributes['is_active'] = $active === '' ? ($v['existing'] ? null : true) : ! in_array($active, ['no', 'n', 'false', '0', 'inactive'], true);
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

    /** A phone typed into a number cell comes back as a number without its leading 0 (712345678). */
    private function phoneText(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            $value = sprintf('%.0f', $value);
        }

        return trim((string) $value);
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
