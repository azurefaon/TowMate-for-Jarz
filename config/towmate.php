<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Team Leader Demo Arrival
    |--------------------------------------------------------------------------
    |
    | When enabled, the Team Leader mobile app's "Demo Arrival" action is
    | allowed to skip the physical GPS proximity check for an arrival-claim
    | status transition (arrived_pickup / arrived_dropoff). Every other
    | validation (auth, TL ownership, current status, allowed transition)
    | still applies unconditionally — this flag only ever affects the GPS
    | radius check. Must default to false so a production deployment rejects
    | is_demo=true even if TL_DEMO_ARRIVAL_ENABLED is never set.
    |
    */

    'demo_arrival_enabled' => env('TL_DEMO_ARRIVAL_ENABLED', false),

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

];
