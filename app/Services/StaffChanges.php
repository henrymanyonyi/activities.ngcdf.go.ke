<?php

namespace App\Services;

use App\Exceptions\ActivityWorkflowException;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Staff;
use App\Models\StaffChange;
use App\Models\User;
use App\Notifications\ActivityAlert;
use App\Support\MobileNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Promotions, transfers and changes of system access (25 October 2026). The
 * only writer of an officer's position and of the account made from them:
 *
 *  - The officer's department and designation (their HR position) and their
 *    FAPM access (a role in User::ROLES, or none) are separate, and stay so.
 *    A designation never grants access; only the access chosen here does.
 *  - The System Administrator, the CEO, the Chief of Staff and the Assistant
 *    change positions and appoint or remove Heads of Department
 *    (`staff.reassign`). Giving or taking away an office role (CEO, Chief of
 *    Staff, Assistant, System Administrator) also needs `users.manage`. Nobody
 *    changes their own access.
 *  - One active Head per department: appointing a new Head deactivates the
 *    current one only when asked to. A Head moved to another department must
 *    be made Head there or lose their access, so no one keeps access to a
 *    department they have left. Accounts are deactivated, never deleted, and
 *    their sessions end so the new access applies at once.
 *  - Every change is recorded in staff_changes with the effective date and
 *    the reason; the account and staff records are audited as usual.
 *  - An officer given access on an account that was not already active (a new
 *    account, or one reactivated) is emailed a link to set their own password
 *    (27 October 2026), so they can sign in without being handed one.
 *  - An officer who leaves a department stops being its Manager.
 */
class StaffChanges
{
    public const KEEP = 'keep';

    public const NONE = 'none';

    /** Roles held by exactly one active account in the whole Board. */
    private const OFFICE_ROLES = [User::ROLE_CEO, User::ROLE_CHIEF_OF_STAFF, User::ROLE_ASSISTANT_CHIEF_OF_STAFF, User::ROLE_ADMIN];

    public function __construct(private Alerts $alerts, private AuditLogger $audit) {}

    /** The FAPM account belonging to the officer: linked to them, or else with their email address (and then linked). */
    public function accountOf(Staff $staff): ?User
    {
        return User::query()->with('roles')->where('staff_id', $staff->id)->first()
            ?? ($staff->email ? User::query()->with('roles')->whereNull('staff_id')->whereRaw('LOWER(email) = ?', [mb_strtolower($staff->email)])->first() : null);
    }

    /**
     * The system access the user may give the officer: keep, none, Head of Department, and the office roles for users.manage.
     *
     * @return array<string, string>
     */
    public function accessOptions(User $actor): array
    {
        $options = [self::KEEP => 'Keep their current access', User::ROLE_HOD => 'Head of Department (of the department above)', self::NONE => 'No system access'];

        if ($actor->can('users.manage')) {
            foreach (self::OFFICE_ROLES as $role) {
                $options[$role] = User::ROLES[$role];
            }
        }

        return $options;
    }

    /**
     * @param  array{department_id: int|string|null, designation_id?: int|string|null, access: string, replace_head?: bool, effective_on: string, reason: string, reference?: ?string}  $data
     * @return array{change: StaffChange, password: ?string, account: ?User}
     */
    public function apply(Staff $staff, array $data, User $actor): array
    {
        if (! $actor->can('staff.reassign')) {
            throw new AuthorizationException('You are not permitted to change officers\' positions or access.');
        }

        $access = $data['access'];
        if (! array_key_exists($access, $this->accessOptions($actor)) && ! in_array($access, self::OFFICE_ROLES, true)) {
            throw new ActivityWorkflowException('Choose the system access.');
        }
        if (trim((string) $data['reason']) === '') {
            throw new ActivityWorkflowException('Give the reason or the administrative reference for the change.');
        }

        return DB::transaction(function () use ($staff, $data, $actor, $access) {
            $staff = Staff::query()->lockForUpdate()->findOrFail($staff->id);
            $account = $this->accountOf($staff);
            $fromRole = $account?->is_active ? $account->roleName() : null;
            $department = filled($data['department_id'] ?? null) ? Department::query()->findOrFail((int) $data['department_id']) : null;
            $designationId = array_key_exists('designation_id', $data) ? (filled($data['designation_id']) ? (int) $data['designation_id'] : null) : $staff->designation_id;
            $designationId && Designation::query()->findOrFail($designationId);
            $toRole = match ($access) {
                self::KEEP => $fromRole,
                self::NONE => null,
                default => $access,
            };

            $this->assertAllowed($staff, $account, $actor, $fromRole, $toRole, $access, $department);

            // The HR position.
            [$fromDepartment, $fromDesignation] = [$staff->department_id, $staff->designation_id];
            $staff->update(['department_id' => $department?->id, 'designation_id' => $designationId]);
            if ((int) $fromDepartment !== (int) $department?->id) {
                Department::query()->where('manager_staff_id', $staff->id)->get()->each->update(['manager_staff_id' => null]);
            }

            // The system access.
            $password = null;
            if ($toRole !== $fromRole || ($toRole === User::ROLE_HOD && (int) $account?->department_id !== (int) $department?->id)) {
                if ($toRole === User::ROLE_HOD) {
                    $this->replaceHead($department, $account, (bool) ($data['replace_head'] ?? false), $data, $actor);
                }
                [$account, $password] = $this->setAccess($staff, $account, $toRole, $department);
            }

            $change = StaffChange::create([
                'staff_id' => $staff->id,
                'user_id' => $account?->id,
                'from_department_id' => $fromDepartment,
                'to_department_id' => $department?->id,
                'from_designation_id' => $fromDesignation,
                'to_designation_id' => $designationId,
                'from_role' => $fromRole,
                'to_role' => $toRole,
                'effective_on' => $data['effective_on'],
                'reason' => trim((string) $data['reason']),
                'reference' => filled($data['reference'] ?? null) ? mb_substr(trim((string) $data['reference']), 0, 100) : null,
                'changed_by' => $actor->id,
            ]);

            if ($account?->is_active && $toRole !== $fromRole) {
                $this->alerts->toUsers([$account->fresh(['roles'])], ActivityAlert::accessChanged($toRole, $department));
            }
            if ($account?->is_active && $fromRole === null && $toRole !== null) {
                $this->sendPasswordLink($account, User::ROLES[$toRole]);
            }

            return ['change' => $change, 'password' => $password, 'account' => $account];
        });
    }

    private function assertAllowed(Staff $staff, ?User $account, User $actor, ?string $fromRole, ?string $toRole, string $access, ?Department $department): void
    {
        if ($account && $account->is($actor) && ($toRole !== $fromRole || ($fromRole === User::ROLE_HOD && (int) $department?->id !== (int) $account->department_id))) {
            throw new AuthorizationException('You cannot change your own system access. Ask another authorised officer.');
        }

        $touchesOffice = in_array($fromRole, self::OFFICE_ROLES, true) || in_array($toRole, self::OFFICE_ROLES, true);
        if ($touchesOffice && $toRole !== $fromRole && ! $actor->can('users.manage')) {
            throw new AuthorizationException('Only the System Administrator, the CEO or the Chief of Staff gives or takes away an office role.');
        }

        if ($toRole === User::ROLE_HOD && ! $department) {
            throw new ActivityWorkflowException('Choose the department the officer will head.');
        }

        if ($access === self::KEEP && $fromRole === User::ROLE_HOD && (int) $department?->id !== (int) $account?->department_id) {
            throw new ActivityWorkflowException("{$staff->name} is Head of {$account->department?->name}. Moving them to another department ends that: choose \"Head of Department\" to make them Head of the new department, or \"No system access\".");
        }

        if (in_array($toRole, self::OFFICE_ROLES, true) && $toRole !== $fromRole) {
            $holder = User::query()->role($toRole)->where('is_active', true)->when($account, fn ($q) => $q->whereKeyNot($account->id))->first();
            if ($holder) {
                throw new ActivityWorkflowException("{$holder->name} is the active ".User::ROLES[$toRole].'. Deactivate that account in User accounts first.');
            }
        }

        if ($toRole !== null && ! $account && ! $staff->email) {
            throw new ActivityWorkflowException("{$staff->name} has no email on the staff list, so no account can be made. Add their work email first.");
        }
        if ($toRole !== null && ! $account && User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $staff->email)])->exists()) {
            throw new ActivityWorkflowException("Another account already uses {$staff->email}.");
        }
    }

    /** The department's current Head, if another officer, is deactivated only when the change says so. */
    private function replaceHead(Department $department, ?User $account, bool $replace, array $data, User $actor): void
    {
        $current = User::query()->role(User::ROLE_HOD)->where('is_active', true)->where('department_id', $department->id)
            ->when($account, fn ($q) => $q->whereKeyNot($account->id))->first();
        if (! $current) {
            return;
        }
        if (! $replace) {
            throw new ActivityWorkflowException("{$current->name} is the active Head of {$department->name}. Tick \"Replace the current Head\" to end their access, or change them first.");
        }
        if ($current->is($actor)) {
            throw new AuthorizationException('You cannot end your own access as Head of Department.');
        }

        $this->deactivate($current);
        if ($current->staff_id) {
            StaffChange::create([
                'staff_id' => $current->staff_id, 'user_id' => $current->id,
                'from_department_id' => $department->id, 'to_department_id' => $department->id,
                'from_role' => User::ROLE_HOD, 'to_role' => null,
                'effective_on' => $data['effective_on'], 'reason' => 'Replaced as Head of '.$department->name.': '.trim((string) $data['reason']),
                'reference' => filled($data['reference'] ?? null) ? mb_substr(trim((string) $data['reference']), 0, 100) : null,
                'changed_by' => $actor->id,
            ]);
        } else {
            $this->audit->record($current, 'head_of_department_replaced', new: ['department_id' => $department->id]);
        }
    }

    /**
     * Gives the officer the role (making their account if they have none) or takes their access away.
     *
     * @return array{0: ?User, 1: ?string} the account and, for a new one, its one-time password
     */
    private function setAccess(Staff $staff, ?User $account, ?string $role, ?Department $department): array
    {
        if ($role === null) {
            if ($account?->is_active) {
                $this->deactivate($account);
            }

            return [$account, null];
        }

        $password = null;
        if (! $account) {
            $password = Str::password(16);
            $account = User::create([
                'name' => $staff->name,
                'email' => mb_strtolower(trim((string) $staff->email)),
                'phone' => MobileNumber::normalise($staff->phone),
                'staff_id' => $staff->id,
                'password' => $password,
                'is_active' => true,
            ]);
            $account->forceFill(['email_verified_at' => now()])->save();
        }

        $account->forceFill([
            'staff_id' => $staff->id,
            'department_id' => $role === User::ROLE_HOD ? $department->id : null,
            'is_active' => true,
        ])->save();
        $account->syncRoles([$role]);
        $this->endSessions($account);

        return [$account, $password];
    }

    /** After the change commits; a failed email never undoes it (they can still use "Forgot password?"). */
    private function sendPasswordLink(User $account, string $access): void
    {
        DB::afterCommit(function () use ($account, $access) {
            try {
                $account->sendPasswordLink($access);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    private function deactivate(User $user): void
    {
        $user->update(['is_active' => false]);
        $this->endSessions($user);
    }

    /** Sign the person out everywhere, so their new access (or none) applies from their next request. */
    private function endSessions(User $user): void
    {
        DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
    }
}
