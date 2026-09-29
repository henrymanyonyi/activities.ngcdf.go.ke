<?php

return [
    // FRD CF-03: two-factor authentication is mandatory for all three accounts.
    'require_two_factor' => (bool) env('ACTIVITIES_REQUIRE_TWO_FACTOR', true),

    // Magic-link intake of submissions from memo originators. FRD 2.2 / BR-11
    // put all access by departments out of scope, so this stays off until the
    // CEO confirms it (see ACTIVITIES-DESIGN-ASSESSMENT.md §0).
    'submission_links' => (bool) env('ACTIVITIES_SUBMISSION_LINKS', false),

    // FRD CF-04: lock an account after repeated failed sign-ins.
    'lockout_attempts' => (int) env('ACTIVITIES_LOCKOUT_ATTEMPTS', 5),
    'lockout_minutes' => (int) env('ACTIVITIES_LOCKOUT_MINUTES', 30),

    // Upload limits (kilobytes).
    'max_document_kb' => 10240,
    'max_list_kb' => 2048,
];
