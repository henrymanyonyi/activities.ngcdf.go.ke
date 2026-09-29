<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional enforcement of FRD CF-03, off by default (ACTIVITIES_REQUIRE_TWO_FACTOR).
 * When on, until two-factor is confirmed the only page a signed-in user can
 * reach is their profile, where they set it up.
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (config('activities.require_two_factor') && $user && $user->two_factor_confirmed_at === null) {
            return redirect()->route('profile.show')
                ->with('notify', ['type' => 'warning', 'message' => 'Set up two-factor authentication to continue.']);
        }

        return $next($request);
    }
}
