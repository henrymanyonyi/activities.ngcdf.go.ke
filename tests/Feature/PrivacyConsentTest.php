<?php

use App\Http\Controllers\PrivacyStatementController;
use App\Livewire\Submit;
use App\Models\Activity;
use App\Models\PrivacyConsent;
use App\Models\User;
use App\Services\SubmissionLinkService;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
| NG-CDF Board Data Privacy Statement: the public page and anonymous consent,
| and the one-time consent screen for signed-in accounts (same design as
| Smart NG-CDF's PrivacyConsentTest).
*/

function privacyCookie(): string
{
    return config('privacy.cookie');
}

/** Agrees on the public page as a visitor and returns the token the browser now holds. */
function consentAnonymously(): string
{
    return test()->post(route('privacy.statement.consent'), ['agree' => '1'])
        ->assertRedirect()
        ->getCookie(privacyCookie())
        ->getValue();
}

function heldOnConsent(TestResponse $response, string $consentRoute): bool
{
    return $response->isRedirect(route($consentRoute));
}

// The public statement

it('lets anyone read the statement and download the PDF without signing in', function () {
    $this->get(route('privacy.statement'))
        ->assertOk()
        ->assertSee('Version 1.0')
        ->assertSee('1. Introduction')
        ->assertSee('18. Contact Us')
        ->assertSee('I have read and understood');

    $response = $this->get(route('privacy.statement.download'))->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('NG-CDF-Board-Data-Privacy-Statement-v1.0.pdf');
});

it('records a visitor consent without any personal details', function () {
    $this->post(route('privacy.statement.consent'))->assertSessionHasErrors('agree');
    expect(PrivacyConsent::count())->toBe(0);

    $token = consentAnonymously();

    $consent = PrivacyConsent::sole();
    expect($consent->consent_type)->toBe(PrivacyConsent::TYPE_ANONYMOUS)
        ->and($consent->privacy_statement_version)->toBe('1.0')
        ->and($consent->ip_address)->toBeNull()
        ->and($consent->user_agent)->toBeNull()
        ->and($token)->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($consent->consent_token_hash)->toBe(hash('sha256', $token));
});

it('does not ask a returning visitor again for the same version', function () {
    $token = consentAnonymously();

    $this->withCookie(privacyCookie(), $token)->get(route('privacy.statement'))
        ->assertSee('You have agreed to version 1.0')
        ->assertDontSee('I have read and understood');

    $again = $this->withCookie(privacyCookie(), $token)->post(route('privacy.statement.consent'), ['agree' => '1']);
    expect($again->getCookie(privacyCookie())->getValue())->toBe($token)
        ->and(PrivacyConsent::count())->toBe(1);
});

it('asks a visitor again when the version changes', function () {
    $token = consentAnonymously();

    config(['privacy.statement.version' => '1.1']);

    $this->withCookie(privacyCookie(), $token)->get(route('privacy.statement'))
        ->assertSee('I have read and understood');
});

it('does not accept a forged, tampered or lapsed cookie', function () {
    $token = consentAnonymously();

    $this->withUnencryptedCookie(privacyCookie(), $token)->get(route('privacy.statement'))->assertSee('I have read and understood');
    $this->withCookie(privacyCookie(), str_repeat('a', 64))->get(route('privacy.statement'))->assertSee('I have read and understood');
    $this->withCookie(privacyCookie(), '1.0')->get(route('privacy.statement'))->assertSee('I have read and understood');

    $this->travel(config('privacy.anonymous_consent_days') + 1)->days();
    $this->withCookie(privacyCookie(), $token)->get(route('privacy.statement'))->assertSee('I have read and understood');
});

// Signed-in accounts

describe('signed-in users', function () {
    it('holds an account that has not consented on the consent screen', function () {
        $subject = User::factory()->ceo()->withoutPrivacyConsent()->create();

        expect(heldOnConsent($this->actingAs($subject, 'web')->get(route('dashboard')), 'privacy.consent.show'))->toBeTrue();

        $this->actingAs($subject, 'web')->get(route('privacy.consent.show'))
            ->assertOk()
            ->assertSee('Version 1.0')
            ->assertSee('Agree &amp; Continue', false)
            ->assertSee(route('privacy.statement.download'));
    });

    it('cannot be agreed without ticking the box', function () {
        $subject = User::factory()->ceo()->withoutPrivacyConsent()->create();

        $this->actingAs($subject, 'web')->post(route('privacy.consent.accept'))->assertSessionHasErrors('agree');

        expect(PrivacyConsent::where('user_id', $subject->id)->exists())->toBeFalse()
            ->and(heldOnConsent($this->actingAs($subject, 'web')->get(route('dashboard')), 'privacy.consent.show'))->toBeTrue();
    });

    it('records the consent once and returns the account to where it was going', function () {
        $subject = User::factory()->ceo()->withoutPrivacyConsent()->create();

        $this->actingAs($subject, 'web')->get(route('dashboard'));
        $this->actingAs($subject, 'web')->post(route('privacy.consent.accept'), ['agree' => '1'])->assertRedirect(route('dashboard'));
        $this->actingAs($subject, 'web')->post(route('privacy.consent.accept'), ['agree' => '1'])->assertRedirect();

        expect(PrivacyConsent::where('user_id', $subject->id)->where('consent_type', PrivacyConsent::TYPE_AUTHENTICATED)->where('privacy_statement_version', '1.0')->count())->toBe(1)
            ->and(heldOnConsent($this->actingAs($subject, 'web')->get(route('dashboard')), 'privacy.consent.show'))->toBeFalse();
    });

    it('asks again when the statement version changes', function () {
        $subject = User::factory()->ceo()->create();
        expect(heldOnConsent($this->actingAs($subject, 'web')->get(route('dashboard')), 'privacy.consent.show'))->toBeFalse();

        config(['privacy.statement.version' => '1.1']);

        expect(heldOnConsent($this->actingAs($subject, 'web')->get(route('dashboard')), 'privacy.consent.show'))->toBeTrue();
    });

    it('does not let an anonymous consent stand in for the account holder', function () {
        $token = consentAnonymously();
        $subject = User::factory()->ceo()->withoutPrivacyConsent()->create();

        expect(heldOnConsent($this->withCookie(privacyCookie(), $token)->actingAs($subject, 'web')->get(route('dashboard')), 'privacy.consent.show'))->toBeTrue();
    });

    it('never treats the consent cookie as a way in', function () {
        $token = consentAnonymously();

        $this->withCookie(privacyCookie(), $token)->get(route('dashboard'))->assertRedirect();
        $this->withCookie(privacyCookie(), $token)->get(route('privacy.consent.show'))->assertRedirect();
        $this->assertGuest('web');
    });
});

// Public forms that collect personal data: the magic-link activity submission

it('sends a link holder who has not consented to the statement and back', function () {
    config(['activities.submission_links' => true]);
    Route::get('/submit/{token}', Submit::class)->name('submit.show');

    [, $token] = app(SubmissionLinkService::class)->create(['label' => 'Q2 plan', 'recipient_name' => 'HOD PPME', 'expires_at' => now()->addDays(7), 'max_submissions' => 1], User::factory()->chiefOfStaff()->create());

    Livewire::test(Submit::class, ['token' => $token])->assertRedirect(route('privacy.statement'));
    expect(session(PrivacyStatementController::RETURN_TO_KEY))->toBe(route('submit.show', $token));

    $this->post(route('privacy.statement.consent'), ['agree' => '1'])->assertRedirect(route('submit.show', $token));

    // A consent that lapses while the form is open stops the submission.
    $component = Livewire::withCookie(privacyCookie(), visitorPrivacyConsent())
        ->test(Submit::class, ['token' => $token])
        ->assertNoRedirect();

    config(['privacy.statement.version' => '1.1']);

    $component->call('submit')->assertRedirect(route('privacy.statement'));
    expect(Activity::count())->toBe(0);
});
