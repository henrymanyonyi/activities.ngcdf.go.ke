<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * FRD Section 4.1 permission matrix for the three roles, with the CEO given
 * every permission (OI-13), plus the System Administrator (settings,
 * configuration and logs only) and Heads of Department (their own
 * department's memos only). To change it, edit MATRIX and re-run the
 * seeder. Safe to re-run; removes any role that is not in MATRIX.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /** @var array<string, string> */
    public const PERMISSIONS = [
        'activities.view' => 'View dashboards, calendar and register',
        'activities.manage' => 'Create and edit activities, participants and costs',
        'activities.submit' => 'Submit an activity for the CEO\'s decision',
        'activities.decide' => 'Approve, decline or return an activity',
        'decisions.record' => 'Record a decision given outside the system',
        'directives.manage' => 'Issue directives and action items',
        'activities.postpone' => 'Postpone an activity',
        'activities.cancel' => 'Cancel an activity',
        'activities.execute' => 'Record commencement, attendance, reports and actual costs',
        'activities.close' => 'Close an activity',
        'reference.manage' => 'Maintain reference data (staff, DSA rates, thresholds)',
        'links.manage' => 'Create and edit submission links',
        'reports.export' => 'Export and print',
        'users.manage' => 'Manage the three user accounts',
        'audit.view' => 'View audit trail and access log',
        'memos.originate' => 'Prepare and submit memos for their own department (Head of Department)',
        'memos.review' => 'Review memos, comment, return them for correction and forward them',
        'staff.add' => 'See the staff list and add officers who are missing (correct only those they added)',
        'budgets.prepare' => 'Prepare department budgets and adjustments as drafts and submit them to the CEO',
        'budgets.approve' => 'Approve department budgets and adjustments, or return them for correction',
        'instructions.send' => 'Instruct Finance (imprest) and Procurement (tickets) to act on an approved memo',
        'instructions.action' => 'Acknowledge and complete the CEO\'s instructions addressed to their own department',
        'workplans.prepare' => 'Prepare their own department\'s workplan and budget estimates and submit them to the CEO',
        'staff.reassign' => 'Change an officer\'s department, designation or system access (promotion, transfer), with a reason',
    ];

    /** @var array<string, list<string>> */
    public const MATRIX = [
        // Every permission: the CEO can do everything the Chief of Staff and the
        // Assistant can, and alone decides in the system (business owner's
        // decision on OI-13, 30 September 2026).
        User::ROLE_CEO => [
            'activities.view', 'activities.manage', 'activities.submit', 'activities.decide', 'decisions.record', 'directives.manage',
            'activities.postpone', 'activities.cancel', 'activities.execute', 'activities.close',
            'reference.manage', 'links.manage', 'reports.export', 'users.manage', 'audit.view',
            'memos.originate', 'memos.review', 'staff.add', 'budgets.prepare', 'budgets.approve',
            'instructions.send', 'instructions.action', 'staff.reassign', 'workplans.prepare',
        ],
        User::ROLE_CHIEF_OF_STAFF => [
            'activities.view', 'activities.manage', 'activities.submit', 'decisions.record', 'directives.manage',
            'activities.postpone', 'activities.cancel', 'activities.execute', 'activities.close',
            'reference.manage', 'links.manage', 'reports.export', 'users.manage', 'audit.view',
            'memos.review', 'budgets.prepare', 'staff.reassign',
        ],
        User::ROLE_ASSISTANT_CHIEF_OF_STAFF => [
            'activities.view', 'activities.manage', 'activities.submit', 'activities.postpone', 'activities.execute',
            'memos.review', 'budgets.prepare', 'staff.reassign',
        ],
        // Settings, configuration and logs; no activities.view, so no activity, dashboard or report
        // other than the access and audit log (CEO's approval, 11 October 2026).
        User::ROLE_ADMIN => [
            'reference.manage', 'users.manage', 'audit.view', 'reports.export', 'staff.reassign',
        ],
        // Their own department's memos only: prepare, submit, correct and follow them to the
        // CEO's decision. No activities.view, so no register, dashboard, calendar or report.
        // instructions.action: the Heads of Finance and Procurement receive the CEO's instructions on
        // approved memos (instruction sheet only, never the memo; App\Models\ActionInstruction::scopeFor).
        // workplans.prepare: their own department's workplan and budget estimates only (App\Services\Budgeting).
        User::ROLE_HOD => [
            'memos.originate', 'staff.add', 'instructions.action', 'workplans.prepare',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(self::PERMISSIONS) as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (self::MATRIX as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->forceFill(['label' => User::ROLES[$roleName]])->save();
            $role->syncPermissions($permissions);
        }

        // CF-01: no role outside the matrix may exist.
        Role::query()->whereNotIn('name', array_keys(self::MATRIX))->get()->each->delete();
        Permission::query()->whereNotIn('name', array_keys(self::PERMISSIONS))->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
