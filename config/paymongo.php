<?php

/*
|--------------------------------------------------------------------------
| PayMongo — SANDBOX ONLY
|--------------------------------------------------------------------------
|
| An isolated, disabled-by-default TEST MODE integration (hosted Checkout v2).
| Nothing here can take live money: a secret key that is not an `sk_test_` key
| is refused, and live-mode responses/events are rejected.
|
| Deliberately NOT under config('services.paymongo'): that key stays null.
|
| Never put real values in this file. Set them in the environment only.
|
*/

$csv = fn (?string $value): array => array_values(array_unique(array_filter(array_map(
    fn (string $item) => strtolower(trim($item)),
    explode(',', (string) $value),
))));

return [
    // Master switch. Off everywhere unless explicitly turned on.
    'enabled' => (bool) env('PAYMONGO_SANDBOX_ENABLED', false),

    // The sandbox only runs in these APP_ENV values. Production is not in the default list.
    'allowed_environments' => $csv(env('PAYMONGO_SANDBOX_ENVIRONMENTS', 'local,testing,staging')) ?: ['local', 'testing'],

    // Production is refused even when listed above. The capstone demo opts in here, explicitly and separately; it
    // still runs TEST MODE only (live keys are always rejected) and only for the allow-listed demo accounts.
    'allow_production' => filter_var(env('PAYMONGO_SANDBOX_ALLOW_PRODUCTION', false), FILTER_VALIDATE_BOOLEAN),

    // Only these accounts may start a test checkout (emails and/or numeric user ids). Blank = nobody.
    'test_user_emails' => $csv(env('PAYMONGO_SANDBOX_TEST_USER_EMAILS', '')),
    'test_user_ids' => array_map('intval', $csv(env('PAYMONGO_SANDBOX_TEST_USER_IDS', ''))),

    // Credentials come from the environment only. Never logged, never returned by any endpoint.
    'secret_key' => env('PAYMONGO_SECRET_KEY'),
    'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),

    // A returned checkout link must be https on one of these domains (or a subdomain) before a customer ever sees it.
    'checkout_url_hosts' => $csv(env('PAYMONGO_CHECKOUT_URL_HOSTS', 'paymongo.com')) ?: ['paymongo.com'],

    'base_url' => rtrim((string) env('PAYMONGO_API_BASE_URL', 'https://api.paymongo.com'), '/'),
    'http_timeout_seconds' => 15,

    // TowMate method => PayMongo payment_method_types value. Only GCash is wired.
    // `bank_transfer` (Online Banking) is intentionally absent: PayMongo documents `dob`
    // for the Payment Intent flow only, not for Checkout Sessions. Add it only after a
    // test-mode Checkout Session with that channel is confirmed to work.
    'channels' => [
        'gcash' => 'gcash',
    ],

    // PayMongo sessions stay `active` until expired through the API, so TowMate owns the deadline.
    'session_ttl_minutes' => (int) env('PAYMONGO_SESSION_TTL_MINUTES', 30),

    // Pages the hosted checkout sends the customer back to. A redirect is NEVER payment proof.
    'success_url' => env('PAYMONGO_SUCCESS_URL'),
    'cancel_url' => env('PAYMONGO_CANCEL_URL'),

    // How far a timestamped webhook signature may drift from now.
    'webhook_tolerance_seconds' => (int) env('PAYMONGO_WEBHOOK_TOLERANCE_SECONDS', 300),
];
