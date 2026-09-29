<?php

namespace App\Listeners;

use App\Services\AccessLogger;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;

/**
 * FRD CF-05 / CF-06: one active session per user, and every sign-in logged.
 * Signing in ends the user's sessions on every other device.
 */
class RecordSignIn
{
    public function __construct(private AccessLogger $log) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getAuthIdentifier())
                ->where('id', '!=', session()->getId())
                ->delete();
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $this->log->log(AccessLogger::SIGN_IN, userId: $user->getAuthIdentifier(), email: $user->email);
    }
}
