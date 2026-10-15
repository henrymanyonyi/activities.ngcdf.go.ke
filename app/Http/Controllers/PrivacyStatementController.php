<?php

namespace App\Http\Controllers;

use App\Services\PrivacyConsentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The NG-CDF Board Data Privacy Statement: the public page anyone can read
 * and agree to without an account, its PDF, and the consent screen a
 * signed-in account is held on (by EnsurePrivacyConsent) until it agrees to
 * the version in force.
 *
 * Where to go after agreeing is only ever a URL this application put in the
 * session itself (RETURN_TO_KEY), never one taken from the request, so the
 * consent forms cannot be used as an open redirect.
 */
class PrivacyStatementController extends Controller
{
    public const RETURN_TO_KEY = 'privacy_consent.return_to';

    public function __construct(private PrivacyConsentService $consents) {}

    public function show(Request $request): View
    {
        return view('privacy.show', [
            'version' => $this->consents->version(),
            'consented' => $this->consents->visitorHasConsented($request),
            'returnTo' => $request->session()->get(self::RETURN_TO_KEY),
        ]);
    }

    public function download(): BinaryFileResponse
    {
        $version = $this->consents->version();

        return response()->download(
            resource_path(config('privacy.statement.pdf')),
            "NG-CDF-Board-Data-Privacy-Statement-v{$version}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Consent from the public page. A signed-in account agreeing here agrees
     * as itself; anyone else gets an anonymous consent and its cookie.
     */
    public function consent(Request $request): RedirectResponse
    {
        $this->validateAgreement($request);

        $returnTo = $request->session()->pull(self::RETURN_TO_KEY);
        $signedIn = $this->consents->signedIn();

        if ($signedIn !== null) {
            [$guard, $subject] = $signedIn;
            $this->consents->recordFor($subject, $request);

            return redirect()->to($returnTo ?? route(config("privacy.subjects.{$guard}.home")));
        }

        $cookie = $this->consents->recordForVisitor($request);

        return redirect()->to($returnTo ?? route('privacy.statement'))
            ->withCookie($cookie)
            ->with('privacy_status', 'Thank you. Your consent to version '.$this->consents->version().' of the Data Privacy Statement has been recorded.');
    }

    /** The mandatory consent screen after sign-in. */
    public function required(Request $request, string $guard = 'web'): View|RedirectResponse
    {
        if ($this->consents->hasConsented(Auth::guard($guard)->user())) {
            return $this->onward($request, $guard);
        }

        return view('privacy.consent', [
            'version' => $this->consents->version(),
            'acceptRoute' => config("privacy.subjects.{$guard}.accept_route"),
            'logoutRoute' => config("privacy.subjects.{$guard}.logout_route"),
        ]);
    }

    public function accept(Request $request, string $guard = 'web'): RedirectResponse
    {
        $this->validateAgreement($request);

        $this->consents->recordFor(Auth::guard($guard)->user(), $request);

        return $this->onward($request, $guard);
    }

    private function onward(Request $request, string $guard): RedirectResponse
    {
        return redirect()->to($request->session()->pull(self::RETURN_TO_KEY) ?? route(config("privacy.subjects.{$guard}.home")));
    }

    private function validateAgreement(Request $request): void
    {
        $request->validate(
            ['agree' => 'accepted'],
            ['agree.accepted' => 'Please tick the box to confirm you have read and agree to the Data Privacy Statement.'],
        );
    }
}
