<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Terms of Use / Privacy Policy Versions
    |--------------------------------------------------------------------------
    |
    | The version identifiers a customer account must have accepted to be
    | considered current. Bumping either value means every account (password
    | or Google) whose stored terms_version/privacy_version no longer match
    | will be required to re-accept before their next login goes through —
    | see User::hasAcceptedCurrentTerms().
    |
    */

    'current_terms_version' => env('TOWMATE_TERMS_VERSION', '1.0'),

    'current_privacy_version' => env('TOWMATE_PRIVACY_VERSION', '1.0'),

    /*
    |--------------------------------------------------------------------------
    | Team Leader Demo Arrival (presentation only)
    |--------------------------------------------------------------------------
    |
    | OFF by default. local/testing always allow the demo-arrival simulator for
    | the seeded demo fixture; production allows it ONLY while this is true.
    | It never relaxes the fixture checks (demo Team Leader account + demo
    | booking marker + ownership + lifecycle transition) — see TlDemoFixture.
    |
    */

    'demo_arrival_enabled' => filter_var(env('TL_DEMO_ARRIVAL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

];
