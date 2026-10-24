<?php

namespace App\Services;

use App\Enums\EmploymentType;
use App\Models\DsaRate;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Adding and correcting officers on the staff list (MD-01). The Office of the
 * CEO maintains every record. A Head of Department may add an officer who is
 * missing, so they can build a team without waiting, and may correct only the
 * records they added; they cannot deactivate anyone.
 */
class StaffRecords
{
    /**
     * @param  array{staff_number: ?string, name: string, employment_type?: string, contract_ends_on?: ?string, email?: ?string, phone?: ?string, department_id: int|string, designation_id?: mixed, job_grade?: ?string, office_id?: mixed, is_active?: bool}  $attributes
     */
    public function save(array $attributes, User $user, ?Staff $staff = null): Staff
    {
        if (! $this->mayEdit($user, $staff)) {
            throw new AuthorizationException($staff ? 'Only the Office of the CEO can change an officer you did not add.' : 'You are not permitted to add officers.');
        }

        if (! $user->can('reference.manage')) {
            // Activation is the Office of the CEO's decision.
            unset($attributes['is_active']);
        }

        $attributes = array_merge($attributes, [
            'staff_number' => filled($attributes['staff_number'] ?? null) ? strtoupper(trim($attributes['staff_number'])) : null,
            'job_grade' => filled($attributes['job_grade'] ?? null) ? (DsaRate::normaliseGrade($attributes['job_grade']) ?? strtoupper(trim($attributes['job_grade']))) : null,
        ]);

        // A casual or attachee without a job grade is costed at the INTERN DSA rate.
        $type = EmploymentType::tryFrom((string) ($attributes['employment_type'] ?? '')) ?? $staff?->employment_type ?? EmploymentType::Permanent;
        if ($attributes['job_grade'] === null && ! $type->requiresStaffNumber()) {
            $attributes['job_grade'] = DsaRate::INTERN;
        }

        if (array_key_exists('is_active', $attributes)) {
            $attributes['exited_on'] = $attributes['is_active'] ? null : ($staff?->exited_on ?? today());
        }

        if ($staff) {
            $staff->update($attributes);

            return $staff;
        }

        return Staff::create($attributes + [
            'is_active' => true,
            'source' => $user->can('reference.manage') ? 'manual' : 'department',
            'joined_on' => today(),
            'created_by' => $user->id,
        ]);
    }

    /** Null staff means adding a new officer. */
    public function mayEdit(User $user, ?Staff $staff = null): bool
    {
        if ($user->can('reference.manage')) {
            return true;
        }

        if (! $user->can('staff.add')) {
            return false;
        }

        return $staff === null || (int) $staff->created_by === $user->id;
    }
}
