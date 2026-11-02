<?php

/*
 * Real concurrency for the PayMongo SANDBOX foundation: one PHP process and Postgres connection per
 * request (see HomeAssignmentConcurrencyTest for the harness). The provider is a local stand-in
 * (paymongo_stub.php) on 127.0.0.1, so nothing leaves the machine and no credential is real.
 *
 *  - several customers' taps on "Pay" at once open exactly ONE session;
 *  - the same signed webhook delivered several times at once is processed exactly once;
 *  - the same payment arriving under two event ids at once is recorded exactly once.
 */

use App\Models\Booking;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\TruckType;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/Support.php';

uses(Tests\TestCase::class, DatabaseTruncation::class);

const OPC_WEBHOOK_SECRET = 'whsec_concurrency_fake';

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Concurrency tests need PostgreSQL.');
    }
});

/** Locks matching rows on a separate connection until the test commits, so workers queue up behind it. */
function opcHold(string $table, string $where): PDO
{
    $holder = ccHolder();
    $holder->beginTransaction();
    $holder->query("SELECT id FROM {$table} WHERE {$where} FOR UPDATE");

    return $holder;
}

function opcBooking(\App\Models\Customer $customer): Booking
{
    $truck = TruckType::firstOrCreate(['name' => 'OPC Truck'], ['base_rate' => 1500, 'per_km_rate' => 60]);

    // The allow-list lives only in the workers' environment, so mark the demo booking explicitly (it is not fillable).
    return tap(new Booking([
        'customer_id' => $customer->id, 'truck_type_id' => $truck->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 10,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 2100, 'vat_exclusive_total' => 2100,
        'vat_amount' => 252.35, 'final_total' => 2352.35, 'service_type' => 'book_now', 'status' => Booking::STATUS_AWAITING_PAYMENT,
    ]), function (Booking $booking) {
        $booking->is_demo = true;
        $booking->save();
    });
}

/** Starts the local provider stand-in and returns [process, base url, counter file]. */
function opcStartStub(): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    $counter = tempnam(sys_get_temp_dir(), 'pms_stub_');
    file_put_contents($counter, '0');

    $server = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/paymongo_stub.php'], __DIR__, ['PMS_STUB_COUNTER' => $counter]);
    $server->start();

    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($probe) {
            fclose($probe);

            return [$server, "http://127.0.0.1:{$port}", $counter];
        }
        usleep(100_000);
    }

    $server->stop();
    throw new RuntimeException('Provider stand-in did not start.');
}

/** Environment that turns the sandbox on inside a worker process. */
function opcEnv(int $userId, ?string $baseUrl = null): array
{
    return array_filter([
        'PAYMONGO_SANDBOX_ENABLED' => 'true',
        'PAYMONGO_SANDBOX_ENVIRONMENTS' => 'testing',
        'PAYMONGO_SECRET_KEY' => 'sk_test_concurrency_fake',
        'PAYMONGO_WEBHOOK_SECRET' => OPC_WEBHOOK_SECRET,
        'PAYMONGO_SANDBOX_TEST_USER_IDS' => (string) $userId,
        'PAYMONGO_API_BASE_URL' => $baseUrl,
        'PAYMONGO_CHECKOUT_URL_HOSTS' => 'stub.invalid', // the local stand-in's links point at checkout.stub.invalid
    ]);
}

function opcCheckout(\App\Models\User $user, Booking $booking, string $baseUrl, string $name): Process
{
    return ccSpawn([
        'actor_id' => $user->id, 'method' => 'POST',
        'uri' => url("/api/v1/bookings/{$booking->booking_code}/online-payment/checkout"),
        'data' => ['method' => 'gcash'], 'json' => true, 'app_name' => $name,
        'extra_env' => opcEnv($user->id, $baseUrl),
    ]);
}

function opcPaidEvent(string $eventId, string $sessionId, string $paymentId, int $amount, string $reference): array
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

/** PayMongo's documented header: t=<timestamp>,te=<test signature>,li=<live signature>; signed over "<t>.<raw body>". */
function opcSignature(array $payload): string
{
    $t = (string) time();

    return "t={$t},te=" . hash_hmac('sha256', $t . '.' . json_encode($payload), OPC_WEBHOOK_SECRET) . ',li=';
}

function opcWebhook(\App\Models\User $actor, array $payload, string $name): Process
{
    return ccSpawn([
        'actor_id' => $actor->id, 'method' => 'POST', 'uri' => url('/api/webhooks/paymongo'),
        'data' => $payload, 'json' => true, 'app_name' => $name,
        'server' => ['HTTP_PAYMONGO_SIGNATURE' => opcSignature($payload)],
        'extra_env' => opcEnv($actor->id),
    ]);
}

it('opens exactly one session when several checkout requests arrive at once', function () {
    [$user, $customer] = ccCustomer();
    $booking = opcBooking($customer);
    [$server, $baseUrl, $counter] = opcStartStub();

    try {
        $holder = opcHold('bookings', "id = {$booking->id}");
        $workers = [
            opcCheckout($user, $booking, $baseUrl, 'cc_pay_1'),
            opcCheckout($user, $booking, $baseUrl, 'cc_pay_2'),
            opcCheckout($user, $booking, $baseUrl, 'cc_pay_3'),
        ];
        expect(ccAwaitWaiting(3))->toBeTrue();
        $holder->commit();

        $results = array_map('ccResult', $workers);
    } finally {
        $server->stop();
    }

    foreach ($results as $result) {
        ccNoServerError($result);
        expect($result['status'])->toBeIn([200, 201, 409], json_encode($result));
    }

    $statuses = collect($results)->pluck('status');
    expect($statuses->filter(fn ($s) => $s === 201))->toHaveCount(1); // one request created it
    expect(PaymentTransaction::where('status', 'pending')->count())->toBe(1);
    expect(PaymentTransaction::count())->toBe(1);
    expect((int) file_get_contents($counter))->toBe(1); // and the provider was asked once

    $transaction = PaymentTransaction::firstOrFail();
    expect($transaction->provider_checkout_session_id)->toBe('cs_test_stub_1');
    expect($transaction->amount_centavos)->toBe(235235);
    expect($booking->fresh()->payment_method)->toBeNull();
    @unlink($counter);
});

it('processes one webhook delivered several times at once exactly once', function () {
    [$user, $customer] = ccCustomer();
    $booking = opcBooking($customer);
    $transaction = PaymentTransaction::create([
        'reference' => 'TMP-OPC-1', 'payable_type' => 'booking', 'payable_id' => $booking->id,
        'booking_id' => $booking->id, 'customer_id' => $customer->id, 'method' => 'gcash',
        'amount_centavos' => 235235, 'status' => 'pending', 'provider_checkout_session_id' => 'cs_race',
        'expires_at' => now()->addMinutes(20),
    ]);
    $payload = opcPaidEvent('evt_race', 'cs_race', 'pay_race', 235235, 'TMP-OPC-1');

    // Hold the transaction row so the first delivery inserts its event row and then waits; the others queue behind its unique key.
    $holder = opcHold('payment_transactions', "id = {$transaction->id}");
    $workers = [
        opcWebhook($user, $payload, 'cc_hook_1'),
        opcWebhook($user, $payload, 'cc_hook_2'),
        opcWebhook($user, $payload, 'cc_hook_3'),
    ];
    expect(ccAwaitWaiting(3))->toBeTrue();
    $holder->commit();

    $results = array_map('ccResult', $workers);
    foreach ($results as $result) {
        ccNoServerError($result);
        expect($result['status'])->toBe(200, json_encode($result));
    }

    $outcomes = collect($results)->map(fn ($r) => $r['body']['outcome'])->sort()->values()->all();
    expect($outcomes)->toBe(['paid', 'replay', 'replay']);
    expect(PaymentWebhookEvent::where('provider_event_id', 'evt_race')->count())->toBe(1);
    expect(PaymentTransaction::where('status', 'paid')->count())->toBe(1);
    expect($transaction->fresh()->provider_payment_id)->toBe('pay_race');
    expect($booking->fresh()->status)->toBe('confirmed'); // submitted exactly once despite the racing deliveries
    expect(\App\Models\Quotation::where('source_booking_id', $booking->id)->count())->toBe(1);
});

it('records one payment once when it is delivered under two different event ids at once', function () {
    [$user, $customer] = ccCustomer();
    $booking = opcBooking($customer);
    $transaction = PaymentTransaction::create([
        'reference' => 'TMP-OPC-2', 'payable_type' => 'booking', 'payable_id' => $booking->id,
        'booking_id' => $booking->id, 'customer_id' => $customer->id, 'method' => 'gcash',
        'amount_centavos' => 235235, 'status' => 'pending', 'provider_checkout_session_id' => 'cs_race2',
        'expires_at' => now()->addMinutes(20),
    ]);

    $holder = opcHold('payment_transactions', "id = {$transaction->id}");
    $workers = [
        opcWebhook($user, opcPaidEvent('evt_a', 'cs_race2', 'pay_same', 235235, 'TMP-OPC-2'), 'cc_hook_a'),
        opcWebhook($user, opcPaidEvent('evt_b', 'cs_race2', 'pay_same', 235235, 'TMP-OPC-2'), 'cc_hook_b'),
    ];
    expect(ccAwaitWaiting(2))->toBeTrue();
    $holder->commit();

    $results = array_map('ccResult', $workers);
    foreach ($results as $result) {
        ccNoServerError($result);
        expect($result['status'])->toBe(200, json_encode($result));
    }

    $outcomes = collect($results)->map(fn ($r) => $r['body']['outcome'])->sort()->values()->all();
    expect($outcomes)->toBe(['already_paid', 'paid']);
    expect(PaymentTransaction::where('status', 'paid')->count())->toBe(1);
    expect(PaymentWebhookEvent::count())->toBe(2);
    expect($booking->fresh()->payment_method)->toBeNull();
});
