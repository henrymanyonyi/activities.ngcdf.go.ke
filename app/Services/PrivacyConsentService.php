<?php

namespace App\Services;

use App\Models\PrivacyConsent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The one place that decides whether someone has agreed to the NG-CDF Board
 * Data Privacy Statement in force (config('privacy.statement')), and the
 * only writer of privacy_consents. The same design as Smart NG-CDF's.
 *
 * Two contexts, kept apart on purpose:
 *
 * - A signed-in account (each guard in config('privacy.subjects')) consents
 *   as itself; EnsurePrivacyConsent holds it on the consent screen until it
 *   does. Only a consent recorded against that account counts. A consent the
 *   same browser gave earlier as an anonymous visitor does not, because
 *   nothing proves the visitor was that account holder.
 *
 * - A visitor with no account consents anonymously. They get a random
 *   256-bit token in an encrypted, HttpOnly cookie; only its SHA-256 hash is
 *   stored, with the version and time and nothing about the person. The
 *   cookie only answers "has this browser agreed to the current version?":
 *   the version is read from the database row, not the cookie, and the
 *   cookie is never an identity or a way in.
 */
class PrivacyConsentService
{
    public function version(): string
    {
        return (string) config('privacy.statement.version');
    }

    public function hasConsented(Model $subject): bool
    {
        return PrivacyConsent::query()
            ->where('consent_type', PrivacyConsent::TYPE_AUTHENTICATED)
            ->where($this->columnFor($subject), $subject->getKey())
            ->where('privacy_statement_version', $this->version())
            ->exists();
    }

    /**
     * Idempotent: a second submit (double click, another tab) returns the
     * consent already on record rather than adding a row.
     */
    public function recordFor(Model $subject, Request $request): PrivacyConsent
    {
        $attributes = [
            'consent_type' => PrivacyConsent::TYPE_AUTHENTICATED,
            $this->columnFor($subject) => $subject->getKey(),
            'privacy_statement_version' => $this->version(),
        ];

        try {
            return PrivacyConsent::query()->firstOrCreate($attributes, [
                'consented_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            ]);
        } catch (UniqueConstraintViolationException) {
            return PrivacyConsent::query()->where($attributes)->firstOrFail();
        }
    }

    /**
     * The first signed-in account on this request, by guard, if any.
     *
     * @return array{0: string, 1: Model}|null
     */
    public function signedIn(): ?array
    {
        foreach (array_keys($this->subjects()) as $guard) {
            $subject = Auth::guard($guard)->user();

            if ($subject instanceof Model) {
                return [$guard, $subject];
            }
        }

        return null;
    }

    /**
     * Whether whoever is making this request may submit personal data on a
     * public form: a signed-in account that has consented, or a browser
     * holding a live anonymous consent to the current version.
     */
    public function visitorHasConsented(Request $request): bool
    {
        $signedIn = $this->signedIn();

        if ($signedIn !== null && $this->hasConsented($signedIn[1])) {
            return true;
        }

        return $this->anonymousConsent($request) !== null;
    }

    /**
     * Records a visitor's consent and returns the cookie that remembers it.
     * A browser that already holds a live consent to this version keeps its
     * token, so refreshing or agreeing in a second tab adds nothing.
     */
    public function recordForVisitor(Request $request): Cookie
    {
        $token = $this->consentToken($request);

        if ($token !== null && $this->anonymousConsent($request) !== null) {
            return $this->cookie($token);
        }

        $token = bin2hex(random_bytes(32));

        PrivacyConsent::query()->create([
            'consent_type' => PrivacyConsent::TYPE_ANONYMOUS,
            'consent_token_hash' => hash('sha256', $token),
            'privacy_statement_version' => $this->version(),
            'consented_at' => now(),
        ]);

        return $this->cookie($token);
    }

    /** @return non-empty-string */
    public function cookieName(): string
    {
        $name = config('privacy.cookie');

        return is_string($name) && $name !== '' ? $name : 'ngcdf_privacy_consent';
    }

    /**
     * Accounts that consent as themselves, by auth guard (config/privacy.php).
     *
     * @return array<string, array{model: class-string<Model>, column: string, home: string, consent_route: string, accept_route: string, logout_route: string|null}>
     */
    private function subjects(): array
    {
        return config('privacy.subjects', []);
    }

    private function columnFor(Model $subject): string
    {
        foreach ($this->subjects() as $config) {
            if ($subject instanceof $config['model']) {
                return $config['column'];
            }
        }

        throw new InvalidArgumentException('No privacy consent subject is configured for '.$subject::class.'.');
    }

    /** The browser's consent token, if it holds a well-formed one. */
    private function consentToken(Request $request): ?string
    {
        $token = $request->cookie($this->cookieName());

        return is_string($token) && preg_match('/\A[0-9a-f]{64}\z/', $token) ? $token : null;
    }

    private function anonymousConsent(Request $request): ?PrivacyConsent
    {
        $token = $this->consentToken($request);

        if ($token === null) {
            return null;
        }

        return PrivacyConsent::query()
            ->where('consent_type', PrivacyConsent::TYPE_ANONYMOUS)
            ->where('consent_token_hash', hash('sha256', $token))
            ->where('privacy_statement_version', $this->version())
            ->where('consented_at', '>=', now()->subDays($this->anonymousConsentDays()))
            ->first();
    }

    private function cookie(string $token): Cookie
    {
        return cookie(
            name: $this->cookieName(),
            value: $token,
            minutes: $this->anonymousConsentDays() * 24 * 60,
            httpOnly: true,
            sameSite: 'lax',
        );
    }

    private function anonymousConsentDays(): int
    {
        return (int) config('privacy.anonymous_consent_days', 365);
    }
}
