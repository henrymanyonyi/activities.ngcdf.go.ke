<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\AccessLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password. The emailed link proves the
     * person holds the account, so the lockout from failed attempts ends too.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => Hash::make($input['password']),
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        app(AccessLogger::class)->log(AccessLogger::PASSWORD_RESET, userId: $user->id, email: $user->email);
    }
}
