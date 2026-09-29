<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * FRD Section 4.1 permission matrix for the three roles. OI-13 is still open:
 * if the CEO changes the matrix, change MATRIX here and re-run the seeder.
 * Safe to re-run; removes any role that is not one of the three.
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
        'reference.manage' => 'Maintain reference data (staff, DSA rates, budget lines)',
        'links.manage' => 'Create and edit submission links',
        'reports.export' => 'Export and print',
        'users.manage' => 'Manage the three user accounts',
        'audit.view' => 'View audit trail and access log',
    ];

    /** @var array<string, list<string>> */
    public const MATRIX = [
        User::ROLE_CEO => [
            'activities.view', 'activities.manage', 'activities.decide', 'decisions.record', 'directives.manage',
            'activities.postpone', 'activities.cancel', 'activities.execute', 'activities.close',
            'links.manage', 'reports.export', 'audit.view',
        ],
        User::ROLE_CHIEF_OF_STAFF => [
            'activities.view', 'activities.manage', 'activities.submit', 'decisions.record', 'directives.manage',
            'activities.postpone', 'activities.cancel', 'activities.execute', 'activities.close',
            'reference.manage', 'links.manage', 'reports.export', 'users.manage', 'audit.view',
        ],
        User::ROLE_ASSISTANT_CHIEF_OF_STAFF => [
            'activities.view', 'activities.manage', 'activities.submit', 'activities.postpone', 'activities.execute',
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

        // CF-01: no role other than the three may exist.
        Role::query()->whereNotIn('name', array_keys(self::MATRIX))->get()->each->delete();
        Permission::query()->whereNotIn('name', array_keys(self::PERMISSIONS))->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
