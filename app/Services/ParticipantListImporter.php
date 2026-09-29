<?php

namespace App\Services;

use App\Enums\ExternalCategory;
use App\Enums\ParticipantRole;
use App\Enums\ParticipationStatus;
use App\Imports\ParticipantListSheet;
use App\Models\Activity;
use App\Models\ActivityParticipant;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Office;
use App\Models\Region;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Reads and applies a participant list in the published format
 * (App\Exports\ParticipantListTemplate). Staff are matched by staff number,
 * then by name within the department; anyone not found is added to the staff
 * register, and unknown departments/designations/offices/regions are created
 * (decision Q6). Existing staff details are only filled in where blank, never
 * overwritten — Admin corrects the register in Settings.
 */
class ParticipantListImporter
{
    public const MAX_ROWS = 500;

    /** Slugged heading => heading as printed in the template. */
    public const COLUMNS = [
        'staff_number' => 'Staff Number',
        'name' => 'Name',
        'department' => 'Department',
        'designation' => 'Designation',
        'job_grade' => 'Job Grade',
        'office' => 'Office',
        'region' => 'Region',
        'role' => 'Role',
        'days' => 'Days',
        'external' => 'External',
        'organisation' => 'Organisation',
        'external_category' => 'External Category',
        'email' => 'Email',
        'phone' => 'Phone',
    ];

    /**
     * Read and validate a file. Nothing is written.
     *
     * @return array{rows: list<array<string, mixed>>, errors: list<string>}
     */
    public function parse(string $path): array
    {
        try {
            $sheets = Excel::toArray(new ParticipantListSheet, $path);
        } catch (Throwable) {
            return ['rows' => [], 'errors' => ['The file could not be read. Upload the Excel (.xlsx) or CSV template.']];
        }

        $raw = $sheets[0] ?? [];

        if ($raw === []) {
            return ['rows' => [], 'errors' => ['The list has no participants.']];
        }

        if (! array_key_exists('name', $raw[0])) {
            return ['rows' => [], 'errors' => ['The "Name" column is missing. Use the template headings unchanged.']];
        }

        if (count($raw) > self::MAX_ROWS) {
            return ['rows' => [], 'errors' => ['A list can hold at most '.self::MAX_ROWS.' participants.']];
        }

        return $this->validateRows($raw);
    }

    /**
     * @param  list<array<string, mixed>>  $raw
     * @return array{rows: list<array<string, mixed>>, errors: list<string>}
     */
    public function validateRows(array $raw): array
    {
        $rows = [];
        $errors = [];
        $seenNumbers = [];

        foreach ($raw as $index => $cells) {
            $line = $index + 2; // heading is row 1
            $value = fn (string $key): string => trim((string) ($cells[$key] ?? ''));

            $name = Department::normaliseName($value('name'));
            if ($name === '') {
                $errors[] = "Row {$line}: Name is required.";

                continue;
            }

            $isExternal = in_array(strtolower($value('external')), ['yes', 'y', 'true', '1'], true);

            $role = ParticipantRole::fromInput($value('role'));
            if (! $role) {
                $errors[] = "Row {$line}: Role \"{$value('role')}\" is not one of ".implode(', ', array_map(fn ($r) => $r->label(), ParticipantRole::cases())).'.';

                continue;
            }

            $days = $value('days');
            if ($days !== '' && (! ctype_digit($days) || (int) $days > 366)) {
                $errors[] = "Row {$line}: Days must be a whole number between 0 and 366.";

                continue;
            }

            $category = null;
            if ($isExternal) {
                $category = ExternalCategory::fromInput($value('external_category'));
                if (! $category) {
                    $errors[] = "Row {$line}: External Category \"{$value('external_category')}\" is not recognised.";

                    continue;
                }
            }

            $staffNumber = strtoupper($value('staff_number'));
            if (! $isExternal && $staffNumber !== '') {
                if (isset($seenNumbers[$staffNumber])) {
                    $errors[] = "Row {$line}: Staff Number {$staffNumber} also appears on row {$seenNumbers[$staffNumber]}.";

                    continue;
                }
                $seenNumbers[$staffNumber] = $line;
            }

            $email = $value('email');
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Row {$line}: Email \"{$email}\" is not valid.";

                continue;
            }

            $rows[] = [
                'line' => $line,
                'is_external' => $isExternal,
                'staff_number' => $isExternal ? null : ($staffNumber !== '' ? $staffNumber : null),
                'name' => mb_substr($name, 0, 255),
                'department' => $value('department'),
                'designation' => $value('designation'),
                'job_grade' => strtoupper(mb_substr($value('job_grade'), 0, 20)),
                'office' => $value('office'),
                'region' => $value('region'),
                'role' => $role,
                'days' => $days === '' ? null : (int) $days,
                'organisation' => mb_substr($value('organisation'), 0, 255),
                'external_category' => $category,
                'email' => $email !== '' ? mb_substr($email, 0, 255) : null,
                'phone' => $value('phone') !== '' ? mb_substr($value('phone'), 0, 30) : null,
            ];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Add validated rows to an activity. Rows already on the activity (same staff member) are updated.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{added: int, updated: int, new_staff: int, externals: int}
     */
    public function apply(Activity $activity, array $rows): array
    {
        $summary = ['added' => 0, 'updated' => 0, 'new_staff' => 0, 'externals' => 0];

        DB::transaction(function () use ($activity, $rows, &$summary) {
            foreach ($rows as $row) {
                if ($row['is_external']) {
                    $this->addExternal($activity, $row);
                    $summary['externals']++;

                    continue;
                }

                [$staff, $created] = $this->resolveStaff($row);
                $summary['new_staff'] += (int) $created;

                $participant = ActivityParticipant::firstOrNew(['activity_id' => $activity->id, 'staff_id' => $staff->id]);
                $summary[$participant->exists ? 'updated' : 'added']++;

                $participant->fill([
                    'is_external' => false,
                    'role' => $row['role'],
                    'days_planned' => $row['days'],
                ]);
                if (! $participant->exists) {
                    $participant->status = ParticipationStatus::Nominated;
                    $participant->snapshotFrom($staff);
                }
                $participant->save();
            }

            app(DsaCalculator::class)->recalculate($activity);
        });

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: Staff, 1: bool} the staff member and whether they were created
     */
    public function resolveStaff(array $row): array
    {
        $department = Department::resolveByName($row['department']);
        $designation = Designation::resolveByName($row['designation']);
        // Regions are the fixed list of ten (MD-04): match by name or code, never create.
        $region = $row['region'] === '' ? null : Region::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($row['region'])])
            ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($row['region']).' region'])
            ->orWhereRaw('UPPER(code) = ?', [strtoupper($row['region'])])
            ->first();
        $office = Office::resolveByName($row['office'], ['region_id' => $region?->id]);

        $staff = null;
        if ($row['staff_number']) {
            $staff = Staff::withTrashed()->where('staff_number', $row['staff_number'])->first();
        }

        if (! $staff) {
            $staff = Staff::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($row['name'])])
                ->when($department, fn ($q) => $q->where('department_id', $department->id))
                ->when($row['staff_number'], fn ($q) => $q->whereNull('staff_number'))
                ->first();
        }

        if (! $staff) {
            $staff = Staff::create([
                'staff_number' => $row['staff_number'],
                'name' => $row['name'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'department_id' => $department?->id,
                'designation_id' => $designation?->id,
                'job_grade' => $row['job_grade'] ?: null,
                'office_id' => $office?->id,
                'source' => 'submission',
            ]);

            return [$staff, true];
        }

        if ($staff->trashed()) {
            $staff->restore();
        }

        // Fill blanks only.
        $fill = array_filter([
            'staff_number' => $staff->staff_number ? null : $row['staff_number'],
            'email' => $staff->email ? null : $row['email'],
            'phone' => $staff->phone ? null : $row['phone'],
            'department_id' => $staff->department_id ? null : $department?->id,
            'designation_id' => $staff->designation_id ? null : $designation?->id,
            'job_grade' => $staff->job_grade ? null : ($row['job_grade'] ?: null),
            'office_id' => $staff->office_id ? null : $office?->id,
        ]);
        if ($fill !== []) {
            $staff->update($fill);
        }

        return [$staff, false];
    }

    /** @param  array<string, mixed>  $row */
    private function addExternal(Activity $activity, array $row): void
    {
        $existing = $activity->participants()
            ->where('is_external', true)
            ->whereRaw('LOWER(external_name) = ?', [mb_strtolower($row['name'])])
            ->first();

        ($existing ?? new ActivityParticipant(['activity_id' => $activity->id, 'status' => ParticipationStatus::Nominated]))
            ->fill([
                'is_external' => true,
                'staff_id' => null,
                'external_name' => $row['name'],
                'external_organisation' => $row['organisation'] ?: null,
                'external_category' => $row['external_category'],
                'role' => $row['role'],
                'days_planned' => $row['days'],
            ])->save();
    }
}
