<?php

namespace App\Http\Middleware;

use App\Http\Controllers\PrivacyStatementController;
use App\Services\PrivacyConsentService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a signed-in account on the Data Privacy Statement consent screen
 * until it has agreed, as itself, to the version in force. It asks once per
 * version: the consent lasts until the Legal Department issues a new one
 * (config/privacy.php), and then everyone is asked again. There is no way
 * past it but agreeing or signing out.
 *
 * Used as `privacy.consent:{guard}` on the signed-in route groups, after the
 * app's own sign-in gates. An anonymous consent from the same browser does
 * not count here (see PrivacyConsentService).
 */
class EnsurePrivacyConsent
{
    public function __construct(private PrivacyConsentService $consents) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $guard = 'web'): Response
    {
        $subject = Auth::guard($guard)->user();

        if (! $subject instanceof Model
            || $request->hasHeader('X-Livewire')
            || $request->routeIs(...config('privacy.exempt_routes', []))
            || $this->consents->hasConsented($subject)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'You must agree to the Data Privacy Statement before continuing.'], 403);
        }

        if ($request->isMethod('GET')) {
            $request->session()->put(PrivacyStatementController::RETURN_TO_KEY, $request->fullUrl());
        }

        return redirect()->route(config("privacy.subjects.{$guard}.consent_route"));
    }
}
