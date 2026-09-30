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

];
