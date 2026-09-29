<?php

namespace Database\Factories;

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

    public function chiefOfStaff(): static
    {
        return $this->role(User::ROLE_CHIEF_OF_STAFF);
    }

    public function assistant(): static
    {
        return $this->role(User::ROLE_ASSISTANT_CHIEF_OF_STAFF);
    }
}
