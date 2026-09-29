<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Creates one of the three accounts (FRD CF-01) with a one-time random
 * password, printed once. Two-factor authentication must be set up at first
 * sign-in (CF-03). Refuses a second active account for the same role.
 */
class CreateUser extends Command
{
    protected $signature = 'activities:create-user
        {role : ceo, chief_of_staff or assistant_chief_of_staff}
        {email : Sign-in email address}
        {name : Full name}';

    protected $description = 'Create the CEO, Chief of Staff or Assistant Chief of Staff account';

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

        if (User::query()->role($data['role'])->where('is_active', true)->exists()) {
            $this->error('An active '.User::ROLES[$data['role']].' account already exists. Deactivate it first (CF-04).');

            return self::FAILURE;
        }

        $password = Str::password(16);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
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
