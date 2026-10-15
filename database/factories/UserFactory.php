<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\PrivacyConsent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => now(),
            'remember_token' => Str::random(10),
            'profile_photo_path' => null,
        ];
    }

    /**
     * Factory accounts have agreed to the Data Privacy Statement in force,
     * as a real one would have on first sign-in, so EnsurePrivacyConsent does
     * not hold them on the consent screen.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            PrivacyConsent::query()->create([
                'consent_type' => PrivacyConsent::TYPE_AUTHENTICATED,
                'user_id' => $user->id,
                'privacy_statement_version' => (string) config('privacy.statement.version'),
                'consented_at' => now(),
            ]);
        });
    }

    /**
     * Indicate that the account has not yet agreed to the Data Privacy Statement.
     */
    public function withoutPrivacyConsent(): static
    {
        // Runs after configure()'s callback, so it undoes the default consent.
        return $this->afterCreating(function (User $user): void {
            PrivacyConsent::query()->where('user_id', $user->id)->delete();
        });
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => ['email_verified_at' => null]);
    }

    public function withoutTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => ['two_factor_confirmed_at' => null]);
    }

    /** Requires RolesAndPermissionsSeeder to have run. */
    public function role(string $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole($role));
    }

    public function ceo(): static
    {
        return $this->role(User::ROLE_CEO);
    }

    public function admin(): static
    {
        return $this->role(User::ROLE_ADMIN);
    }

    public function chiefOfStaff(): static
    {
        return $this->role(User::ROLE_CHIEF_OF_STAFF);
    }

    public function assistant(): static
    {
        return $this->role(User::ROLE_ASSISTANT_CHIEF_OF_STAFF);
    }

    /** A Head of Department of the given department. */
    public function headOf(Department $department): static
    {
        return $this->state(fn (array $attributes) => ['department_id' => $department->id])->role(User::ROLE_HOD);
    }
}
