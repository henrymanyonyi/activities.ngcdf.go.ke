<?php

use App\Models\User;
use Illuminate\Support\Facades\Password;

it('renders the sign-in pages in the Smart NG-CDF style', function () {
    $this->get('/login')->assertOk()
        ->assertSee('NG-CDF FAPM')->assertSee('Restricted')->assertSee('Forgot password?')
        ->assertDontSee('unsplash.com');

    $this->get('/forgot-password')->assertOk()->assertSee('Recover password')->assertSee('Send reset link');
    $this->get('/reset-password/token123?email=a@b.test')->assertOk()->assertSee('Reset password')->assertSee('a@b.test');
});

it('refills the email after a failed sign-in', function () {
    User::factory()->ceo()->create(['email' => 'ceo@ngcdf.go.ke']);

    $this->from('/login')->post('/login', ['email' => 'ceo@ngcdf.go.ke', 'password' => 'wrong'])->assertRedirect('/login');
    $this->get('/login')->assertSee('value="ceo@ngcdf.go.ke"', false)->assertSee('These credentials do not match our records.');
});

it('names a deactivated account only to someone with its password', function () {
    User::factory()->ceo()->create(['email' => 'old@ngcdf.go.ke', 'is_active' => false]);

    $this->post('/login', ['email' => 'old@ngcdf.go.ke', 'password' => 'wrong'])->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
    $this->post('/login', ['email' => 'old@ngcdf.go.ke', 'password' => 'password'])->assertSessionHasErrors(['email' => 'This account has been deactivated. Contact the Chief of Staff.']);
    $this->assertGuest();
});

it('tells the holder of the right password that the account is locked', function () {
    User::factory()->ceo()->create(['email' => 'locked@ngcdf.go.ke', 'locked_until' => now()->addMinutes(10)]);

    $this->post('/login', ['email' => 'locked@ngcdf.go.ke', 'password' => 'wrong'])->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
    $this->post('/login', ['email' => 'locked@ngcdf.go.ke', 'password' => 'password'])->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('locked');
    $this->assertGuest();
});

it('shows the security check for two-factor accounts', function () {
    $user = User::factory()->ceo()->create(['email' => 'tfa@ngcdf.go.ke']);
    $user->forceFill(['two_factor_secret' => encrypt('SECRET'), 'two_factor_recovery_codes' => encrypt(json_encode(['a'])), 'two_factor_confirmed_at' => now()])->save();

    $this->post('/login', ['email' => 'tfa@ngcdf.go.ke', 'password' => 'password'])->assertRedirect('/two-factor-challenge');
    $this->get('/two-factor-challenge')->assertOk()->assertSee('Security check')->assertSee('Use a recovery code');
});

it('sends a reset link only to an existing account', function () {
    User::factory()->ceo()->create(['email' => 'reset@ngcdf.go.ke']);

    $this->post('/forgot-password', ['email' => 'reset@ngcdf.go.ke'])->assertSessionHas('status', __(Password::RESET_LINK_SENT));
});

it('has no registration or email-verification pages (CF-01)', function () {
    $this->get('/register')->assertNotFound();
    expect(view()->exists('auth.register'))->toBeFalse();
});
