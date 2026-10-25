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

    /*
    | Production only: comma-separated allowlist of Team Leader emails that may
    | use the presentation arrival simulator on bookings actually assigned to
    | them, and only while demo_arrival_enabled is true. Entries are trimmed
    | and lowercased; blanks are ignored; unset/blank = nobody.
    */
    /*
    |--------------------------------------------------------------------------
    | Next-task queue offers
    |--------------------------------------------------------------------------
    |
    | How long a Team Leader has to accept or decline a queued next-task offer
    | before it expires and the booking is free to be offered or assigned again.
    |
    */
    'next_task_queue_enabled' => (bool) env('NEXT_TASK_QUEUE_ENABLED', false),

    // Which Team Leaders may receive offers once the queue is on: "*", user ids "12,15", or blank (nobody).
    'next_task_queue_team_leaders' => env('NEXT_TASK_QUEUE_TEAM_LEADERS', ''),

    'queue_offer_ttl_minutes' => (int) env('QUEUE_OFFER_TTL_MINUTES', 10),

    'demo_team_leader_emails' => array_values(array_unique(array_filter(
        array_map(
            fn (string $email) => strtolower(trim($email)),
            explode(',', (string) env('TL_DEMO_TEAM_LEADER_EMAILS', '')),
        ),
        fn (string $email) => $email !== '',
    ))),

];
