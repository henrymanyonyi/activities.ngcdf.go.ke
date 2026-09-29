<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use App\Services\AccessLogger;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // FRD CF-04, with Smart NG-CDF's account-state messages. The password is
        // checked first, so a deactivated or locked account is only named to
        // someone who already knows its password: the page cannot be used to
        // discover which email addresses have accounts.
        Fortify::authenticateUsing(function (Request $request): ?User {
            $field = Fortify::username();
            $email = Str::lower(trim((string) $request->input($field)));
            $user = User::query()->where('email', $email)->first();
            $log = app(AccessLogger::class);

            if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
                if ($user && ! $user->isLocked()) {
                    $attempts = $user->failed_login_attempts + 1;
                    $lock = $attempts >= config('activities.lockout_attempts');
                    $user->forceFill([
                        'failed_login_attempts' => $lock ? 0 : $attempts,
                        'locked_until' => $lock ? now()->addMinutes(config('activities.lockout_minutes')) : $user->locked_until,
                    ])->saveQuietly();
                    $log->log($lock ? AccessLogger::LOCKED_OUT : AccessLogger::FAILED_SIGN_IN, meta: ['attempts' => $attempts], userId: $user->id, email: $email);
                } else {
                    $log->log(AccessLogger::FAILED_SIGN_IN, meta: ['reason' => $user ? 'locked' : 'unknown'], userId: $user?->id, email: $email);
                }

                return null;
            }

            if (! $user->is_active) {
                $log->log(AccessLogger::FAILED_SIGN_IN, meta: ['reason' => 'inactive'], userId: $user->id, email: $email);

                throw ValidationException::withMessages([$field => 'This account has been deactivated. Contact the Chief of Staff.']);
            }

            if ($user->isLocked()) {
                $log->log(AccessLogger::FAILED_SIGN_IN, meta: ['reason' => 'locked'], userId: $user->id, email: $email);

                throw ValidationException::withMessages([$field => 'This account is locked after repeated failed attempts. Try again after '.$user->locked_until->format('H:i').', or ask the Chief of Staff to unlock it.']);
            }

            $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->saveQuietly();

            return $user;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip()
            );
        });
    }
}
