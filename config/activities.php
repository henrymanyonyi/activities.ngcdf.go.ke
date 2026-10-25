<?php

return [
    // Two-factor authentication is optional: users may turn it on in their
    // Profile. Set true to force every account to set it up before using the
    // app (FRD CF-03 asks for this; switched off at the business owner's request).
    'require_two_factor' => (bool) env('ACTIVITIES_REQUIRE_TWO_FACTOR', false),

    // Magic-link intake of submissions from memo originators. FRD 2.2 / BR-11
    // put all access by departments out of scope, so this stays off until the
    // CEO confirms it (see ACTIVITIES-DESIGN-ASSESSMENT.md §0).
    'submission_links' => (bool) env('ACTIVITIES_SUBMISSION_LINKS', false),

    // FRD CF-04: lock an account after repeated failed sign-ins.
    'lockout_attempts' => (int) env('ACTIVITIES_LOCKOUT_ATTEMPTS', 5),
    'lockout_minutes' => (int) env('ACTIVITIES_LOCKOUT_MINUTES', 30),

    // Gill Sans MT font files for generated documents (App\Support\DocumentFont).
    // Licensed with Microsoft Office, so copied onto each server, never committed.
    'document_fonts' => env('ACTIVITIES_DOCUMENT_FONTS', storage_path('app/fonts')),

    // Action-needed email and SMS to Heads of Department, the Chief of Staff and the
    // Assistant (App\Services\OutboundAlerts). They carry the reference only, never
    // the memo's details. Switched on and off in Settings › Thresholds. SMS go through
    // SMS Leopard, as in NG-CDF Careers; "log" only writes them to the log (tests).
    'sms' => [
        'driver' => env('ACTIVITIES_SMS_DRIVER', 'smsleopard'), // smsleopard | log (tests only)
        'smsleopard' => [
            'api_key' => env('SMSLEOPARD_API_KEY'),
            'api_secret' => env('SMSLEOPARD_API_SECRET'),
            'sender_id' => env('SMSLEOPARD_SENDER_ID', 'NG-CDFBOARD'), // must be assigned to the SMS Leopard account
            'base_url' => env('SMSLEOPARD_BASE_URL', 'https://api.smsleopard.com/v1'),
        ],
    ],

    // Upload limits (kilobytes).
    'max_document_kb' => 10240,
    'max_list_kb' => 2048,
];
