<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Staff;
use App\Models\User;
use App\Support\MobileNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Creates one of the accounts in User::ROLES (FRD CF-01) with a one-time random
 * password, printed once. Two-factor authentication must be set up at first
 * sign-in (CF-03). Refuses a second active account for the same role (for a
 * Head of Department, the same department).
 */
class CreateUser extends Command
{
    protected $signature = 'activities:create-user
        {role : ceo, chief_of_staff, assistant_chief_of_staff, admin or hod}
        {email : Sign-in email address}
        {name : Full name}
        {--department= : For hod: the department name, e.g. ICT}
        {--staff= : PF number of the officer on the staff list; their mobile number is picked up}
        {--phone= : Mobile number for the action-needed SMS, e.g. 0712345678}';

    protected $description = 'Create the CEO, Chief of Staff, Assistant Chief of Staff, System Administrator or a Head of Department account';

    public function handle(): int
    {
        $data = [
            'role' => strtolower((string) $this->argument('role')),
            'email' => strtolower(trim((string) $this->argument('email'))),
            'name' => trim((string) $this->argument('name')),
        ];

        $validator = Validator::make($data, [
            'role' => ['required', 'in:'.implode(',', array_keys(User::ROLES))],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $department = null;
        if (in_array($data['role'], User::DEPARTMENTAL_ROLES, true)) {
            $department = Department::query()->where('name', trim((string) $this->option('department')))->first();

            if (! $department) {
                $this->error('Give the department with --department="Name" (as listed in Settings › Departments).');

                return self::FAILURE;
            }
        }

        if (User::query()->role($data['role'])->where('is_active', true)->when($department, fn ($q) => $q->where('department_id', $department->id))->exists()) {
            $this->error('An active '.User::ROLES[$data['role']].' account already exists'.($department ? " for {$department->name}" : '').'. Deactivate it first (CF-04).');

            return self::FAILURE;
        }

        $staff = null;
        if (filled($this->option('staff'))) {
            $staff = Staff::query()->where('staff_number', strtoupper(trim((string) $this->option('staff'))))->first();
            if (! $staff) {
                $this->error('No officer on the staff list has PF number '.$this->option('staff').'.');

                return self::FAILURE;
            }
            if (User::query()->where('staff_id', $staff->id)->exists()) {
                $this->error("{$staff->name} already has an account.");

                return self::FAILURE;
            }
        }

        $phone = filled($this->option('phone')) ? MobileNumber::normalise((string) $this->option('phone')) : MobileNumber::normalise($staff?->phone);
        if (filled($this->option('phone')) && $phone === null) {
            $this->error('Give a Kenyan mobile number with --phone, e.g. 0712345678.');

            return self::FAILURE;
        }

        $password = Str::password(16);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $phone,
            'staff_id' => $staff?->id,
            'department_id' => $department?->id,
            'password' => $password,
            'is_active' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole($data['role']);

        $this->info("Created {$user->roleLabel()} account for {$user->email}.");
        $this->line("One-time password: {$password}");
        $this->warn('Share it privately. Two-factor authentication must be set up at first sign-in.');

        return self::SUCCESS;
    }
}
