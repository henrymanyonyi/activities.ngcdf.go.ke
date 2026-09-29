<?php

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\Hash;

it('seeds one active account per role with the known password', function () {
    $old = User::factory()->ceo()->create(['email' => 'ceo@ngcdf.local']);

    $this->seed(UserSeeder::class);
    $this->seed(UserSeeder::class); // re-runnable

    foreach (UserSeeder::USERS as $role => [$email]) {
        $user = User::where('email', $email)->sole();
        expect($user->hasRole($role))->toBeTrue()
            ->and($user->is_active)->toBeTrue()
            ->and(Hash::check('password', $user->password))->toBeTrue()
            ->and(User::role($role)->where('is_active', true)->count())->toBe(1);
    }

    expect($old->fresh()->is_active)->toBeFalse();
});

it('refuses to run outside local and testing', function () {
    app()->detectEnvironment(fn () => 'production');

    app(UserSeeder::class)->run();
})->throws(RuntimeException::class);
