<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The three accounts for local development and testing, all with the password
 * "password". Refuses to run anywhere else: FRD CF-01 accounts in staging and
 * production are created with `php artisan activities:create-user`.
 *
 * Only one account may hold each role, so any other active holder of the role
 * is deactivated (never deleted). Safe to re-run; it also resets the password,
 * clears a lockout and removes two-factor set-up on these three accounts.
 */
class UserSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /** @var array<string, array{0: string, 1: string}> role => [email, name] */
    public const USERS = [
        User::ROLE_CEO => ['ceo@ngcdf.go.ke', 'Chief Executive Officer'],
        User::ROLE_CHIEF_OF_STAFF => ['cos@ngcdf.go.ke', 'Chief of Staff'],
        User::ROLE_ASSISTANT_CHIEF_OF_STAFF => ['acos@ngcdf.go.ke', 'Assistant Chief of Staff'],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('UserSeeder creates accounts with a known password and only runs when APP_ENV is local or testing. Use php artisan activities:create-user instead.');
        }

        foreach (self::USERS as $role => [$email, $name]) {
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->forceFill([
                'name' => $name,
                'password' => self::PASSWORD,
                'is_active' => true,
                'email_verified_at' => now(),
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();
            $user->syncRoles([$role]);

            User::query()->role($role)->where('is_active', true)->whereKeyNot($user->id)
                ->get()->each(fn (User $other) => $other->update(['is_active' => false]));

            $this->command?->info("{$user->roleLabel()}: {$email} / ".self::PASSWORD);
        }
    }
}
