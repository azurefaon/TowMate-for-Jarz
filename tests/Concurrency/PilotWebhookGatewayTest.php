<?php

/*
 * The pilot's public surface, tested with real servers on 127.0.0.1: tools/pilot/webhook_gateway.php
 * in front of a real Laravel app server (the project's own server.php router) running against the
 * ISOLATED test database. Everything goes over real HTTP, so this proves:
 *
 *  - the internet-facing door forwards ONLY the signed webhook (raw body and signature intact) and a
 *    static return page, and 404s everything else without ever reaching Laravel;
 *  - a correctly signed event for a session we do not know is acknowledged (2xx) but never paid;
 *  - a bad signature is refused and a real payment event is applied through the proxy.
 *
 * No credential here is real and nothing leaves the machine.
 */

use App\Models\Booking;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\TruckType;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/Support.php';

uses(Tests\TestCase::class, DatabaseTruncation::class);

const PGW_SECRET = 'whsec_gateway_test_fake';

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Concurrency tests need PostgreSQL.');
    }
});

function pgwFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    return $port;
}

function pgwWaitFor(int $port): void
{
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        if ($probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2)) {
            fclose($probe);

            return;
        }
        usleep(100_000);
    }

    throw new RuntimeException("Server on port {$port} did not start.");
}

/** @return array{0: Process, 1: Process, 2: string} app server, gateway, gateway base URL */
function pgwStart(): array
{
    $db = config('database.connections.pgsql');
    $appPort = pgwFreePort();
    $gatewayPort = pgwFreePort();

    $app = new Process([PHP_BINARY, '-S', "127.0.0.1:{$appPort}", base_path('server.php')], public_path(), [
        'APP_ENV' => 'testing', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'BCRYPT_ROUNDS' => '4',
        'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'],
        'DB_DATABASE' => $db['database'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => (string) $db['password'],
        'PAYMONGO_SANDBOX_ENABLED' => 'true', 'PAYMONGO_SANDBOX_ENVIRONMENTS' => 'testing',
        'PAYMONGO_SECRET_KEY' => 'sk_test_gateway_fake', 'PAYMONGO_WEBHOOK_SECRET' => PGW_SECRET,
    ]);
    $app->start();

    $gateway = new Process([PHP_BINARY, '-S', "127.0.0.1:{$gatewayPort}", base_path('tools/pilot/webhook_gateway.php')], base_path(), [
        'PILOT_TARGET' => "http://127.0.0.1:{$appPort}",
    ]);
    $gateway->start();

    pgwWaitFor($appPort);
    pgwWaitFor($gatewayPort);

    return [$app, $gateway, "http://127.0.0.1:{$gatewayPort}"];
}

function pgwStop(Process ...$processes): void
{
    foreach ($processes as $process) {
        $process->stop(2);
    }
}

function pgwPaidEvent(string $eventId, string $sessionId, string $paymentId, int $amount, string $reference): array
{
    return ['data' => [
        'id' => $eventId, 'type' => 'event',
        'attributes' => ['type' => 'checkout_session.payment.paid', 'livemode' => false, 'data' => [
            'id' => $sessionId, 'type' => 'checkout_session',
            'attributes' => ['livemode' => false, 'reference_number' => $reference, 'payments' => [[
                'id' => $paymentId, 'type' => 'payment',
                'attributes' => ['status' => 'paid', 'amount' => $amount, 'currency' => 'PHP', 'livemode' => false],
            ]]],
        ]],
    ]];
}

/** Sends a body to the gateway exactly as PayMongo would: raw JSON plus the documented signature header. */
function pgwPost(string $gatewayUrl, string $raw, ?string $signature = 'valid'): \Illuminate\Http\Client\Response
{
    $headers = [];

    if ($signature === 'valid') {
        $t = (string) time();
        $headers['Paymongo-Signature'] = "t={$t},te=" . hash_hmac('sha256', $t . '.' . $raw, PGW_SECRET) . ',li=';
    } elseif ($signature !== null) {
        $headers['Paymongo-Signature'] = $signature;
    }

    return Http::withHeaders($headers)->withBody($raw, 'application/json')->timeout(30)->post($gatewayUrl . '/api/webhooks/paymongo');
}

function pgwPendingTransaction(): PaymentTransaction
{
    [, $customer] = ccCustomer();
    $truck = TruckType::firstOrCreate(['name' => 'PGW Truck'], ['base_rate' => 1500, 'per_km_rate' => 60]);
    $booking = Booking::create([
        'customer_id' => $customer->id, 'truck_type_id' => $truck->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 1,
        'base_rate' => 100, 'per_km_rate' => 0, 'computed_total' => 100, 'vat_exclusive_total' => 100,
        'vat_amount' => 0, 'final_total' => 100, 'service_type' => 'book_now', 'status' => 'arrived_dropoff',
    ]);

    return PaymentTransaction::create([
        'reference' => 'TMP-GW-1', 'payable_type' => 'booking', 'payable_id' => $booking->id,
        'booking_id' => $booking->id, 'customer_id' => $customer->id, 'method' => 'gcash',
        'amount_centavos' => 10000, 'status' => 'pending', 'provider_checkout_session_id' => 'cs_gw_known',
        'expires_at' => now()->addMinutes(20),
    ]);
}

it('acknowledges a correctly signed event for a session it does not know, and never records it as paid', function () {
    $transaction = pgwPendingTransaction();
    [$app, $gateway, $url] = pgwStart();

    try {
        // What a dashboard "send test event" looks like: valid signature, a session we never created.
        $unknown = pgwPost($url, json_encode(pgwPaidEvent('evt_dash_1', 'cs_from_dashboard', 'pay_dash_1', 10000, 'ORDER-12345')));
        // Valid signature and OUR reference number, but a session id we never recorded.
        $knownReference = pgwPost($url, json_encode(pgwPaidEvent('evt_dash_2', 'cs_unrecorded', 'pay_dash_2', 10000, 'TMP-GW-1')));
    } finally {
        pgwStop($app, $gateway);
    }

    foreach ([$unknown, $knownReference] as $response) {
        expect($response->status())->toBe(200); // acknowledged, so PayMongo does not retry something it can never match
        expect($response->json('outcome'))->toBe('unknown_session');
    }

    expect($transaction->fresh()->status)->toBe('pending');
    expect(PaymentTransaction::where('status', 'paid')->count())->toBe(0);

    // Both are kept as an audit trail, with their outcome, so a human can see what arrived.
    expect(PaymentWebhookEvent::whereIn('provider_event_id', ['evt_dash_1', 'evt_dash_2'])->pluck('outcome')->unique()->all())->toBe(['unknown_session']);
});

it('refuses a bad or missing signature through the gateway and records nothing', function (?string $signature) {
    $transaction = pgwPendingTransaction();
    [$app, $gateway, $url] = pgwStart();

    try {
        $response = pgwPost($url, json_encode(pgwPaidEvent('evt_bad', 'cs_gw_known', 'pay_bad', 10000, 'TMP-GW-1')), $signature);
    } finally {
        pgwStop($app, $gateway);
    }

    expect($response->status())->toBe(401);
    expect($transaction->fresh()->status)->toBe('pending');
    expect(PaymentWebhookEvent::count())->toBe(0);
})->with([
    'garbage' => ['t=1,te=abc,li='],
    'missing header' => [null],
    'bare digest' => [str_repeat('a', 64)],
]);

it('applies a real payment event delivered through the gateway, with the body bytes intact', function () {
    $transaction = pgwPendingTransaction();
    [$app, $gateway, $url] = pgwStart();

    // Pretty-printed with raw non-ASCII bytes: the proxy must not re-encode or trim anything.
    $payload = pgwPaidEvent('evt_ok', 'cs_gw_known', 'pay_ok', 10000, 'TMP-GW-1');
    $payload['data']['attributes']['data']['attributes']['description'] = 'Towing ₱ / pilot';
    $raw = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

    try {
        $first = pgwPost($url, $raw);
        $replay = pgwPost($url, $raw);
    } finally {
        pgwStop($app, $gateway);
    }

    expect($first->status())->toBe(200);
    expect($first->json('outcome'))->toBe('paid');
    expect($replay->json('outcome'))->toBe('replay');
    expect($transaction->fresh()->status)->toBe('paid');
    expect(PaymentTransaction::where('status', 'paid')->count())->toBe(1);
    // A payment webhook never completes or alters the booking.
    expect($transaction->booking->fresh()->status)->toBe('arrived_dropoff');
    expect($transaction->booking->fresh()->payment_method)->toBeNull();
});

it('serves only the webhook and the static return page; every other request is a 404 that never reaches Laravel', function () {
    pgwPendingTransaction();
    [$app, $gateway, $url] = pgwStart();

    try {
        $probes = [
            'GET /' => Http::get($url . '/'),
            'GET /login' => Http::get($url . '/login'),
            'GET /api/user' => Http::get($url . '/api/user'),
            'GET /admin-dashboard' => Http::get($url . '/admin-dashboard'),
            'POST /api/login' => Http::post($url . '/api/login', ['email' => 'x', 'password' => 'y']),
            'POST checkout' => Http::post($url . '/api/v1/bookings/TM-1/online-payment/checkout'),
            'GET status' => Http::get($url . '/api/v1/bookings/TM-1/online-payment'),
            'GET webhook' => Http::get($url . '/api/webhooks/paymongo'),
            'PUT webhook' => Http::put($url . '/api/webhooks/paymongo'),
            'webhook trailing slash' => Http::post($url . '/api/webhooks/paymongo/'),
            'webhook path traversal' => Http::post($url . '/api/webhooks/paymongo/../user'),
            'webhook wrong case' => Http::post($url . '/API/webhooks/paymongo'),
            'POST return page' => Http::post($url . '/pilot/return'),
            'storage' => Http::get($url . '/storage/anything'),
            'env file' => Http::get($url . '/.env'),
        ];
        $return = Http::get($url . '/pilot/return?result=success');
        $oversized = Http::withBody(str_repeat('x', 1_000_001), 'application/json')->post($url . '/api/webhooks/paymongo');
    } finally {
        pgwStop($app, $gateway);
    }

    foreach ($probes as $label => $response) {
        // The gateway's own marker, not Laravel's 404/405/401/redirect pages.
        expect([$label, $response->status(), $response->body()])->toBe([$label, 404, '{"error":"not_found"}']);
    }

    expect($return->status())->toBe(200);
    expect($return->header('Content-Type'))->toContain('text/plain');
    expect($return->body())->toContain('does not confirm any payment');
    expect($oversized->status())->toBe(413);
    expect(PaymentWebhookEvent::count())->toBe(0);
});

it('never relays an upstream error page, stack trace, secret or server banner to the internet', function (string $mode, int $expectedStatus) {
    $upstreamPort = pgwFreePort();
    $gatewayPort = pgwFreePort();
    $upstream = new Process([PHP_BINARY, '-S', "127.0.0.1:{$upstreamPort}", __DIR__ . '/leaky_upstream_stub.php'], __DIR__, ['STUB_MODE' => $mode]);
    $gateway = new Process([PHP_BINARY, '-S', "127.0.0.1:{$gatewayPort}", base_path('tools/pilot/webhook_gateway.php')], base_path(), [
        'PILOT_TARGET' => "http://127.0.0.1:{$upstreamPort}",
    ]);
    $upstream->start();
    $gateway->start();

    try {
        pgwWaitFor($upstreamPort);
        pgwWaitFor($gatewayPort);

        $response = Http::withBody('{}', 'application/json')
            ->timeout(30)->post("http://127.0.0.1:{$gatewayPort}/api/webhooks/paymongo");
        $everything = $response->body() . json_encode($response->headers());
    } finally {
        pgwStop($upstream, $gateway);
    }

    expect($response->status())->toBe($expectedStatus);
    expect($response->body())->toBe('{"error":"upstream_error"}');
    expect($everything)->not->toContain('SECRET_MARKER')->not->toContain('Stack trace')->not->toContain('leaky')->not->toContain('9.9.9');
    // And the gateway adds no PHP banner of its own.
    expect($response->header('X-Powered-By'))->toBe('');
})->with([
    'an HTML 500 with a stack trace' => ['trace', 500],
    'plain text containing a secret' => ['text', 200],
    'a JSON body far larger than any real answer' => ['big-json', 200],
]);

it('adds no PHP version banner to its own replies', function () {
    [$app, $gateway, $url] = pgwStart();

    try {
        $notFound = Http::get($url . '/anything');
        $return = Http::get($url . '/pilot/return');
    } finally {
        pgwStop($app, $gateway);
    }

    expect($notFound->header('X-Powered-By'))->toBe('')->and($return->header('X-Powered-By'))->toBe('');
    expect($notFound->header('Cache-Control'))->toContain('no-store');
});

it('tells PayMongo to retry (5xx) when the app behind the gateway is down, instead of dropping the event', function () {
    [$app, $gateway, $url] = pgwStart();
    $app->stop(2); // the app goes away, the front door stays up

    try {
        $response = pgwPost($url, json_encode(pgwPaidEvent('evt_down', 'cs_x', 'pay_x', 10000, 'TMP-X')));
    } finally {
        pgwStop($gateway);
    }

    expect($response->status())->toBe(502);
    expect(PaymentWebhookEvent::count())->toBe(0);
});
