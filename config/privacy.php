<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| NG-CDF Board Data Privacy Statement
|--------------------------------------------------------------------------
|
| The statement in force, as issued by the Legal Department and first
| published in Smart NG-CDF, which every NG-CDF application mirrors.
| Consent is recorded against `version`, so publishing a new statement means
| adding its view under resources/views/privacy/statements/, its PDF under
| resources/privacy/, and changing the keys below in the same release:
| everyone is then asked to consent again, once, to the new version. Old
| versions' views stay so the wording someone agreed to can always be shown.
|
*/

return [

    'statement' => [
        'version' => '1.0',
        'view' => 'privacy.statements.v1-0',
        'pdf' => 'privacy/NG-CDF-Board-Data-Privacy-Statement-v1.0.pdf',
        'title' => 'NG-CDF Board Data Privacy Statement',
    ],

    'app_name' => 'FAPM',

    'logo' => 'images/ngcdf-logo.png',

    /*
    | Accounts that consent as themselves, by auth guard: the model, its
    | column on privacy_consents, and where its consent screen lives.
    | EnsurePrivacyConsent (`privacy.consent:{guard}`) holds each one there.
    */
    'subjects' => [
        'web' => [
            'model' => User::class,
            'column' => 'user_id',
            'home' => 'dashboard',
            'consent_route' => 'privacy.consent.show',
            'accept_route' => 'privacy.consent.accept',
            'logout_route' => 'logout',
        ],
    ],

    /*
    | Routes inside a gated group that must still work before consent, such
    | as signing out.
    */
    'exempt_routes' => [
        'logout',
    ],

    /*
    | How long a visitor's (no account) consent is remembered on their
    | browser before they are asked again. Signed-in accounts' consent does
    | not expire; it lasts until the statement version changes.
    */
    'anonymous_consent_days' => 365,

    'cookie' => 'fapm_privacy_consent',

];
