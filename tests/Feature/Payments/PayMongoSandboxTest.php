<?php

/*
 * PayMongo SANDBOX integration: every provider interaction here is mocked with Http::fake and every
 * webhook is a locally signed fixture. Nothing in this file talks to PayMongo, and the keys below
 * are obviously fake. A passing run proves our code's behaviour, NOT that PayMongo's real sandbox
 * accepts it (no real sandbox payment has been made).
 */

use App\Models\Booking;
use App\Models\Customer;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Services\OnlinePayments\Money;
use App\Services\OnlinePayments\OnlinePaymentService;
use App\Services\OnlinePayments\WebhookVerifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

const PMS_SECRET_KEY = 'sk_test_fake_key_for_unit_tests';
const PMS_WEBHOOK_SECRET = 'whsec_fake_secret_for_unit_tests';

function pmsRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function pmsCustomerUser(): User
{
    $user = User::factory()->create(['role_id' => pmsRole(5, 'Customer')->id, 'status' => 'active']);
    Customer::create([
        'user_id' => $user->id,
        'full_name' => $user->name,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => $user->email,
    ]);

    return $user->fresh();
}

/** Turns the sandbox on for the given test user, with fake credentials. */
function pmsEnable(User ...$allowed): void
{
    config([
        'paymongo.enabled' => true,
        'paymongo.secret_key' => PMS_SECRET_KEY,
        'paymongo.webhook_secret' => PMS_WEBHOOK_SECRET,
        'paymongo.test_user_ids' => array_map(fn (User $u) => $u->id, $allowed),
        'paymongo.test_user_emails' => [],
        'paymongo.allowed_environments' => ['testing'],
        'paymongo.channels' => ['gcash' => 'gcash'],
        'paymongo.checkout_url_hosts' => ['paymongo.test'],
        'paymongo.session_ttl_minutes' => 30,
        'paymongo.success_url' => null,
        'paymongo.cancel_url' => null,
    ]);
}

function pmsBookingFor(User $user, array $overrides = []): Booking
{
    $truck = TruckType::firstOrCreate(['name' => 'PMS Truck'], ['base_rate' => 1500, 'per_km_rate' => 60]);

    return Booking::create(array_merge([
        'customer_id' => $user->customer->id,
        'truck_type_id' => $truck->id,
        'pickup_address' => 'Pickup A',
        'dropoff_address' => 'Dropoff B',
        'distance_km' => 10,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 2100,
        'vat_exclusive_total' => 2100,
        'vat_amount' => 252.35,
        'final_total' => 2352.35,
        'service_type' => 'book_now',
        'status' => 'arrived_dropoff',
        'preferred_payment_method' => 'gcash',
    ], $overrides));
}

/** A two-vehicle normalized group: ₱2,352.35 + ₱1,000.00, plus ₱100 fee and ₱50 discount = ₱3,402.35. */
function pmsGroupFor(User $user): array
{
    $first = pmsBookingFor($user, ['group_code' => 'GRP-PMS-' . uniqid()]);
    $second = pmsBookingFor($user, ['group_code' => $first->group_code, 'final_total' => 1000.00]);

    $quotation = Quotation::create([
        'quotation_number' => 'PMS-' . uniqid(),
        'source_booking_id' => $first->id,
        'customer_id' => $user->customer->id,
        'truck_type_id' => $first->truck_type_id,
        'pickup_address' => 'Pickup A',
        'dropoff_address' => 'Dropoff B',
        'distance_km' => 10,
        'estimated_price' => 3352.35,
        'additional_fee' => 100,
        'discount' => 50,
        'status' => 'accepted',
        'extra_vehicles' => [['booking_id' => $second->id, 'final_total' => 1000]],
    ]);
    $first->update(['quotation_id' => $quotation->id]);
    $second->update(['quotation_id' => $quotation->id]);

    return [$first->fresh(), $second->fresh(), $quotation];
}

/** Routes the mocked provider: create / retrieve / expire, each overridable per test. */
function pmsFakeGateway(array $over = []): void
{
    // Http::fake() stacks stubs (first match wins), so start from a clean client every time.
    Http::swap(new \Illuminate\Http\Client\Factory());

    $sessionId = $over['session_id'] ?? 'cs_test_session_1';
    $created = 0;

    Http::fake(function ($request) use ($over, $sessionId, &$created) {
        $url = $request->url();

        if (str_ends_with($url, '/v2/checkout_sessions')) {
            // Like the real provider, every created session gets its own id (…_1, …_2) unless a test pins one.
            $id = isset($over['session_id']) ? $sessionId : 'cs_test_session_' . (++$created);

            return $over['create'] ?? Http::response(['data' => [
                'id' => $id,
                'type' => 'checkout_session',
                'attributes' => ['checkout_url' => 'https://checkout.paymongo.test/' . $id, 'livemode' => $over['livemode'] ?? false],
            ]], 200);
        }

        if (str_ends_with($url, '/expire')) {
            return $over['expire'] ?? Http::response(['data' => ['id' => $sessionId, 'attributes' => ['status' => 'expired']]], 200);
        }

        return $over['retrieve'] ?? Http::response(['data' => ['id' => $sessionId, 'attributes' => ['status' => 'active', 'livemode' => false, 'payments' => []]]], 200);
    });
}

function pmsCheckout($test, User $user, Booking $booking, array $body = ['method' => 'gcash'])
{
    Sanctum::actingAs($user, ['*']);

    return $test->postJson("/api/v1/bookings/{$booking->booking_code}/online-payment/checkout", $body);
}

/**
 * A checkout_session.payment.paid event in PayMongo's documented envelope (data.id, data.attributes.type /
 * .livemode / .data = the full Checkout Session with its payments array). The session carries the
 * reference_number we sent, looked up from the stored transaction unless a test overrides it.
 */
function pmsPaidEvent(string $eventId, string $sessionId, string $paymentId, int $amount, string $paymentStatus = 'paid', bool $livemode = false, ?string $reference = null, string $currency = 'PHP'): array
{
    $reference ??= PaymentTransaction::where('provider_checkout_session_id', $sessionId)->value('reference') ?? 'TMP-UNKNOWN';

    return [
        'data' => [
            'id' => $eventId,
            'type' => 'event',
            'attributes' => [
                'type' => 'checkout_session.payment.paid',
                'livemode' => $livemode,
                'data' => [
                    'id' => $sessionId,
                    'type' => 'checkout_session',
                    'attributes' => [
                        'livemode' => false,
                        'reference_number' => $reference,
                        'payments' => [[
                            'id' => $paymentId,
                            'type' => 'payment',
                            'attributes' => ['status' => $paymentStatus, 'amount' => $amount, 'currency' => $currency, 'livemode' => false],
                        ]],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * PayMongo's documented header: t=<timestamp>,te=<test signature>,li=<live signature>, where each
 * signature is HMAC-SHA256("<t>.<raw body>", webhook secret). A test event carries `te`; `$format = 'li'`
 * builds the live-only shape (empty `te`).
 */
function pmsSign(string $raw, string $format = 'te', ?int $timestamp = null, string $secret = PMS_WEBHOOK_SECRET): string
{
    $t = (string) ($timestamp ?? time());
    $mac = hash_hmac('sha256', $t . '.' . $raw, $secret);

    return $format === 'li' ? "t={$t},te=,li={$mac}" : "t={$t},te={$mac},li=";
}

function pmsWebhook($test, array $payload, ?string $signature = null, string $format = 'te')
{
    $raw = json_encode($payload);
    $signature ??= pmsSign($raw, $format);

    return $test->call('POST', '/api/webhooks/paymongo', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PAYMONGO_SIGNATURE' => $signature,
    ], $raw);
}

/** Opens a session through the real endpoint and returns [transaction, booking, user]. */
function pmsOpenSession($test, ?array $over = null): array
{
    $user = pmsCustomerUser();
    pmsEnable($user);
    pmsFakeGateway($over ?? []);
    $booking = pmsBookingFor($user);

    pmsCheckout($test, $user, $booking)->assertCreated();

    return [PaymentTransaction::firstOrFail(), $booking, $user];
}

// ───────────────────────── money ─────────────────────────

it('converts pesos to centavos without float drift', function (string|float|int $pesos, int $centavos) {
    expect(Money::pesosToCentavos($pesos))->toBe($centavos);
})->with([
    ['2352.35', 235235],
    [2352.35, 235235],
    [19.99, 1999],
    ['1234.5', 123450],
    [0.07, 7],
    [1.005, 101],
    [100, 10000],
    ['4536.00', 453600],
]);

it('rejects zero, negative and non-numeric amounts', function (mixed $bad) {
    expect(fn () => Money::pesosToCentavos($bad))->toThrow(InvalidArgumentException::class);
})->with([0, -5, '0.00', 'abc']);

it('formats centavos back as a two-decimal peso string', function () {
    expect(Money::centavosToPesos(235235))->toBe('2352.35');
    expect(Money::centavosToPesos(5))->toBe('0.05');
});

// ───────────────────────── gates: disabled, who, config ─────────────────────────

it('is disabled by default: checkout, status and webhook all answer 404', function () {
    $user = pmsCustomerUser();
    $booking = pmsBookingFor($user);
    config(['paymongo.test_user_ids' => [$user->id]]); // even an allow-listed user cannot use a disabled feature

    expect(config('paymongo.enabled'))->toBeFalse();
    Http::fake();

    pmsCheckout($this, $user, $booking)->assertNotFound();
    $this->getJson("/api/v1/bookings/{$booking->booking_code}/online-payment")->assertNotFound();
    pmsWebhook($this, pmsPaidEvent('evt_1', 'cs_1', 'pay_1', 100), pmsSign('x'))->assertNotFound();

    Http::assertNothingSent();
    expect(PaymentTransaction::count())->toBe(0);
});

it('refuses to run in an environment that is not on the sandbox allow-list', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    config(['paymongo.allowed_environments' => ['staging']]); // tests run as "testing"
    Http::fake();

    pmsCheckout($this, $user, pmsBookingFor($user))->assertNotFound();

    Http::assertNothingSent();
});

it('does not let an ordinary customer start a test checkout, and says nothing about why', function () {
    $tester = pmsCustomerUser();
    $ordinary = pmsCustomerUser();
    pmsEnable($tester);
    Http::fake();
    $booking = pmsBookingFor($ordinary);

    // A plain, generic 404: nothing hints that a sandbox exists or why this user was turned away.
    pmsCheckout($this, $ordinary, $booking)->assertNotFound()
        ->assertExactJson(['success' => false, 'message' => 'Not found.', 'reason' => 'not_found']);

    Http::assertNothingSent();
    expect(PaymentTransaction::count())->toBe(0);
});

it('allows a test user identified by email', function () {
    $user = pmsCustomerUser();
    pmsEnable();
    config(['paymongo.test_user_emails' => [strtolower($user->email)]]);
    pmsFakeGateway();

    pmsCheckout($this, $user, pmsBookingFor($user))->assertCreated();
});

it('does not let one customer pay for another customer\'s booking', function () {
    $owner = pmsCustomerUser();
    $other = pmsCustomerUser();
    pmsEnable($owner, $other);
    pmsFakeGateway();
    $booking = pmsBookingFor($owner);

    pmsCheckout($this, $other, $booking)->assertNotFound();
    $this->getJson("/api/v1/bookings/{$booking->booking_code}/online-payment")->assertNotFound();
    $this->postJson("/api/v1/bookings/{$booking->booking_code}/online-payment/cancel")->assertNotFound();

    expect(PaymentTransaction::count())->toBe(0);
});

it('requires authentication on every customer online-payment route', function () {
    pmsEnable();

    $this->postJson('/api/v1/bookings/TM-1/online-payment/checkout')->assertUnauthorized();
    $this->getJson('/api/v1/bookings/TM-1/online-payment')->assertUnauthorized();
    $this->postJson('/api/v1/bookings/TM-1/online-payment/cancel')->assertUnauthorized();
});

it('reports missing credentials as not configured and sends nothing', function (string $key, string $secret) {
    $user = pmsCustomerUser();
    pmsEnable($user);
    config(['paymongo.secret_key' => $key, 'paymongo.webhook_secret' => $secret]);
    Http::fake();

    pmsCheckout($this, $user, pmsBookingFor($user))->assertStatus(503)->assertJsonPath('reason', 'not_configured');

    Http::assertNothingSent();
    expect(PaymentTransaction::count())->toBe(0);
})->with([
    'no secret key' => ['', PMS_WEBHOOK_SECRET],
    'no webhook secret' => [PMS_SECRET_KEY, ''],
]);

it('rejects live and unrecognised secret keys outright', function (string $key) {
    $user = pmsCustomerUser();
    pmsEnable($user);
    config(['paymongo.secret_key' => $key]);
    Http::fake();

    pmsCheckout($this, $user, pmsBookingFor($user))->assertStatus(503);

    Http::assertNothingSent();
})->with(['sk_live_abcdef', 'pk_test_abcdef', 'whatever']);

// ───────────────────────── checkout creation ─────────────────────────

it('creates a GCash checkout from the server-side amount and leaves the booking untouched', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    pmsFakeGateway();
    $booking = pmsBookingFor($user);

    $response = pmsCheckout($this, $user, $booking)->assertCreated();

    $transaction = PaymentTransaction::firstOrFail();
    expect($transaction->status)->toBe('pending');
    expect($transaction->amount_centavos)->toBe(235235);
    expect($transaction->currency)->toBe('PHP');
    expect($transaction->method)->toBe('gcash');
    expect($transaction->payable_type)->toBe('booking');
    expect($transaction->provider_checkout_session_id)->toBe('cs_test_session_1');
    expect($transaction->livemode)->toBeFalse();

    Http::assertSent(function ($request) use ($transaction) {
        return $request->url() === 'https://api.paymongo.com/v2/checkout_sessions'
            && $request->header('Authorization')[0] === 'Basic ' . base64_encode(PMS_SECRET_KEY . ':')
            && $request['data']['attributes']['line_items'][0]['amount'] === 235235
            && $request['data']['attributes']['line_items'][0]['currency'] === 'PHP'
            && $request['data']['attributes']['payment_method_types'] === ['gcash']
            && $request['data']['attributes']['reference_number'] === $transaction->reference;
    });

    $response->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.amount', '2352.35')
        ->assertJsonPath('data.checkout_url', 'https://checkout.paymongo.test/cs_test_session_1');

    // Nothing the customer sees carries a secret or a provider id.
    $body = $response->getContent();
    expect($body)->not->toContain(PMS_SECRET_KEY)->not->toContain(PMS_WEBHOOK_SECRET)->not->toContain('cs_test_session_1\"');

    // The booking is exactly as it was: not paid, same status, same preference.
    $booking->refresh();
    expect($booking->status)->toBe('arrived_dropoff');
    expect($booking->payment_method)->toBeNull();
    expect($booking->payment_submitted_at)->toBeNull();
    expect($booking->cash_received)->toBeNull();
    expect($booking->preferred_payment_method)->toBe('gcash');
});

it('ignores any amount the client sends', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    pmsFakeGateway();
    $booking = pmsBookingFor($user);

    pmsCheckout($this, $user, $booking, ['method' => 'gcash', 'amount' => 1, 'amount_centavos' => 100, 'final_total' => 1])->assertCreated();

    expect(PaymentTransaction::firstOrFail()->amount_centavos)->toBe(235235);
});

it('says Online Banking is not available yet and contacts nobody', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    Http::fake();

    pmsCheckout($this, $user, pmsBookingFor($user), ['method' => 'bank_transfer'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'channel_unavailable');

    Http::assertNothingSent();
    expect(PaymentTransaction::count())->toBe(0);
});

it('refuses checkout until the tow has reached the drop-off', function (string $status) {
    $user = pmsCustomerUser();
    pmsEnable($user);
    Http::fake();

    pmsCheckout($this, $user, pmsBookingFor($user, ['status' => $status]))->assertStatus(409)->assertJsonPath('reason', 'not_ready');

    Http::assertNothingSent();
})->with(['requested', 'accepted', 'on_the_way', 'in_progress', 'waiting_verification', 'completed']);

it('refuses checkout for a cancelled booking', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    Http::fake();

    pmsCheckout($this, $user, pmsBookingFor($user, ['status' => 'cancelled']))->assertStatus(409)->assertJsonPath('reason', 'nothing_to_pay');

    Http::assertNothingSent();
});

it('refuses checkout when payment was already recorded the normal way', function (array $recorded) {
    $user = pmsCustomerUser();
    pmsEnable($user);
    Http::fake();

    pmsCheckout($this, $user, pmsBookingFor($user, $recorded))->assertStatus(409)->assertJsonPath('reason', 'payment_already_recorded');

    Http::assertNothingSent();
})->with([
    'cash recorded' => [['payment_method' => 'cash', 'cash_received' => 2400]],
    'payment submitted' => [['payment_submitted_at' => '2026-11-01 10:00:00']],
    'method recorded' => [['payment_method' => 'gcash']],
]);

it('hands back the same open session instead of creating a second one', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    pmsFakeGateway();
    $booking = pmsBookingFor($user);

    $first = pmsCheckout($this, $user, $booking)->assertCreated();
    $second = pmsCheckout($this, $user, $booking)->assertOk();

    expect($second->json('data.reference'))->toBe($first->json('data.reference'));
    expect(PaymentTransaction::count())->toBe(1);
    Http::assertSentCount(1);
});

it('answers "being prepared" while another request is still opening the session', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    Http::fake();
    $booking = pmsBookingFor($user);
    PaymentTransaction::create([
        'reference' => 'TMP-PREPARING', 'payable_type' => 'booking', 'payable_id' => $booking->id,
        'booking_id' => $booking->id, 'customer_id' => $user->customer->id, 'method' => 'gcash',
        'amount_centavos' => 235235, 'status' => 'pending', 'expires_at' => now()->addMinutes(20),
    ]);

    pmsCheckout($this, $user, $booking)->assertStatus(409)->assertJsonPath('reason', 'preparing');

    Http::assertNothingSent();
    expect(PaymentTransaction::count())->toBe(1);
});

it('lets the database itself refuse a second open session or a second verified payment for one payable', function () {
    $user = pmsCustomerUser();
    $booking = pmsBookingFor($user);
    $row = fn (string $ref, string $status, ?string $session = null) => [
        'reference' => $ref, 'payable_type' => 'booking', 'payable_id' => $booking->id,
        'booking_id' => $booking->id, 'customer_id' => $user->customer->id, 'method' => 'gcash',
        'amount_centavos' => 235235, 'status' => $status, 'provider_checkout_session_id' => $session,
    ];

    PaymentTransaction::create($row('TMP-A', 'pending'));
    expect(fn () => DB::transaction(fn () => PaymentTransaction::create($row('TMP-B', 'pending'))))->toThrow(UniqueConstraintViolationException::class);

    PaymentTransaction::create($row('TMP-C', 'paid', 'cs_c'));
    expect(fn () => DB::transaction(fn () => PaymentTransaction::create($row('TMP-D', 'paid', 'cs_d'))))->toThrow(UniqueConstraintViolationException::class);

    // Closed attempts are unlimited; only open and verified ones are exclusive.
    PaymentTransaction::create($row('TMP-E', 'expired'));
    PaymentTransaction::create($row('TMP-F', 'expired'));
    expect(PaymentTransaction::count())->toBe(4);
});

it('marks the attempt failed and allows a clean retry when the provider errors', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    $booking = pmsBookingFor($user);

    pmsFakeGateway(['create' => Http::response(['errors' => [['code' => 'parameter_invalid', 'detail' => 'sk_test_leak_me']]], 500)]);
    $failed = pmsCheckout($this, $user, $booking)->assertStatus(502);

    // The provider's own text (which could echo anything) never reaches the customer.
    expect($failed->getContent())->not->toContain('sk_test_leak_me')->not->toContain('parameter_invalid');
    expect(PaymentTransaction::firstOrFail()->status)->toBe('failed');

    pmsFakeGateway();
    pmsCheckout($this, $user, $booking)->assertCreated();

    expect(PaymentTransaction::where('status', 'pending')->count())->toBe(1);
    expect(PaymentTransaction::where('status', 'failed')->count())->toBe(1);
});

it('treats an unreachable provider as a safe failure', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('boom'));

    pmsCheckout($this, $user, pmsBookingFor($user))->assertStatus(502);

    expect(PaymentTransaction::firstOrFail()->status)->toBe('failed');
});

it('refuses a live-mode session even if the provider returns one, and tries to expire it', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    pmsFakeGateway(['livemode' => true]);

    pmsCheckout($this, $user, pmsBookingFor($user))->assertStatus(502);

    $transaction = PaymentTransaction::firstOrFail();
    expect($transaction->status)->toBe('failed');
    expect($transaction->failure_code)->toBe('live_mode_rejected');
    expect($transaction->checkout_url)->toBeNull();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/expire'));
});

// ───────────────────────── webhook: signature ─────────────────────────

it('rejects a webhook with a bad, missing or foreign signature and changes nothing', function (?string $signature) {
    [$transaction] = pmsOpenSession($this);
    $payload = pmsPaidEvent('evt_bad', 'cs_test_session_1', 'pay_1', 235235);

    $response = $this->call('POST', '/api/webhooks/paymongo', [], [], [], array_filter([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PAYMONGO_SIGNATURE' => $signature,
    ]), json_encode($payload));

    $response->assertStatus(401);
    expect($transaction->fresh()->status)->toBe('pending');
    expect(PaymentWebhookEvent::count())->toBe(0);
})->with([
    'missing' => [null],
    'garbage' => ['not-a-signature'],
    'signed with another secret' => [hash_hmac('sha256', '{}', 'someone-elses-secret')],
]);

it('rejects a body that was altered after signing', function () {
    [$transaction] = pmsOpenSession($this);
    $original = json_encode(pmsPaidEvent('evt_t', 'cs_test_session_1', 'pay_1', 100));
    $signature = pmsSign($original);
    $tampered = json_encode(pmsPaidEvent('evt_t', 'cs_test_session_1', 'pay_1', 235235));

    $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => $signature,
    ], $tampered)->assertStatus(401);

    expect($transaction->fresh()->status)->toBe('pending');
});

it('verifies PayMongo\'s documented header: HMAC-SHA256 of "<t>.<raw body>" with the webhook secret, test signature only', function () {
    config(['paymongo.webhook_secret' => PMS_WEBHOOK_SECRET, 'paymongo.webhook_tolerance_seconds' => 300]);
    $verifier = new WebhookVerifier();
    $raw = '{"a":1}';
    $now = 1_800_000_000;
    $t = (string) $now;
    $mac = hash_hmac('sha256', $t . '.' . $raw, PMS_WEBHOOK_SECRET);

    // Built by hand from the documented recipe, independent of the test helper.
    expect($verifier->verify($raw, "t={$t},te={$mac},li=", $now))->toBe(['ok' => true, 'reason' => 'ok']);
    // Upper-case hex and spaces after commas are still the same documented header.
    expect($verifier->verify($raw, "t={$t}, te=" . strtoupper($mac) . ', li=', $now)['ok'])->toBeTrue();
    // The live signature slot is ignored entirely, whatever it holds.
    expect($verifier->verify($raw, "t={$t},te={$mac},li=" . str_repeat('0', 64), $now)['ok'])->toBeTrue();
});

it('refuses live-only, wrong, stale, malformed and missing signatures', function (string $case) {
    config(['paymongo.webhook_secret' => PMS_WEBHOOK_SECRET, 'paymongo.webhook_tolerance_seconds' => 300]);
    $verifier = new WebhookVerifier();
    $raw = '{"a":1}';
    $now = 1_800_000_000;
    $t = (string) $now;
    $mac = hash_hmac('sha256', $t . '.' . $raw, PMS_WEBHOOK_SECRET);

    [$header, $body, $reason] = match ($case) {
        'live-only' => ["t={$t},te=,li={$mac}", $raw, 'no_test_signature'],
        'test slot holds a live-looking value only in li' => ["t={$t},li={$mac}", $raw, 'no_test_signature'],
        'altered body' => ["t={$t},te={$mac},li=", '{"a":2}', 'bad_signature'],
        'signed with another secret' => ["t={$t},te=" . hash_hmac('sha256', $t . '.' . $raw, 'other-secret') . ',li=', $raw, 'bad_signature'],
        'signature over the body alone (no timestamp)' => ["t={$t},te=" . hash_hmac('sha256', $raw, PMS_WEBHOOK_SECRET) . ',li=', $raw, 'bad_signature'],
        'signature over a different timestamp' => ["t={$t},te=" . hash_hmac('sha256', ($now - 1) . '.' . $raw, PMS_WEBHOOK_SECRET) . ',li=', $raw, 'bad_signature'],
        'stale (1 hour old)' => ['t=' . ($now - 3600) . ',te=' . hash_hmac('sha256', ($now - 3600) . '.' . $raw, PMS_WEBHOOK_SECRET) . ',li=', $raw, 'stale_timestamp'],
        'from the future' => ['t=' . ($now + 3600) . ',te=' . hash_hmac('sha256', ($now + 3600) . '.' . $raw, PMS_WEBHOOK_SECRET) . ',li=', $raw, 'stale_timestamp'],
        'no timestamp' => ["te={$mac},li=", $raw, 'malformed_signature'],
        'non-numeric timestamp' => ["t=abc,te={$mac},li=", $raw, 'malformed_signature'],
        'short signature' => ["t={$t},te=00ff,li=", $raw, 'malformed_signature'],
        'non-hex signature' => ["t={$t},te=" . str_repeat('z', 64) . ',li=', $raw, 'malformed_signature'],
        'repeated parameter' => ["t={$t},t={$t},te={$mac},li=", $raw, 'malformed_signature'],
        'unknown parameter' => ["t={$t},te={$mac},li=,x=1", $raw, 'malformed_signature'],
        'bare hex digest, the undocumented form' => [hash_hmac('sha256', $raw, PMS_WEBHOOK_SECRET), $raw, 'malformed_signature'],
        'bare digest of timestamp.body' => [hash_hmac('sha256', $t . '.' . $raw, PMS_WEBHOOK_SECRET), $raw, 'malformed_signature'],
        'oversized header' => ['t=' . $t . ',te=' . str_repeat('a', 600), $raw, 'malformed_signature'],
        'empty' => ['', $raw, 'missing_signature'],
    };

    expect($verifier->verify($body, $header, $now))->toBe(['ok' => false, 'reason' => $reason]);
})->with([
    'live-only', 'test slot holds a live-looking value only in li', 'altered body', 'signed with another secret',
    'signature over the body alone (no timestamp)', 'signature over a different timestamp', 'stale (1 hour old)',
    'from the future', 'no timestamp', 'non-numeric timestamp', 'short signature', 'non-hex signature',
    'repeated parameter', 'unknown parameter', 'bare hex digest, the undocumented form', 'bare digest of timestamp.body',
    'oversized header', 'empty',
]);

it('never disables the timestamp window, even if misconfigured to zero', function () {
    config(['paymongo.webhook_secret' => PMS_WEBHOOK_SECRET, 'paymongo.webhook_tolerance_seconds' => 0]);
    $raw = '{"a":1}';
    $now = 1_800_000_000;

    expect((new WebhookVerifier())->verify($raw, pmsSign($raw, 'te', $now - 86_400), $now)['reason'])->toBe('stale_timestamp');
});

it('keeps the raw body byte-for-byte: pretty-printed JSON, escaped slashes and unicode still verify', function () {
    [$transaction] = pmsOpenSession($this);
    $payload = pmsPaidEvent('evt_raw', 'cs_test_session_1', 'pay_raw', 235235);
    $payload['data']['attributes']['data']['attributes']['description'] = "Towing ₱ \"quoted\" / slash\u{00e9}";
    $raw = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    expect($raw)->toContain('₱')->toContain('/ slash'); // genuinely non-ASCII, unescaped bytes

    $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => pmsSign($raw),
    ], $raw)->assertOk()->assertJsonPath('outcome', 'paid');

    expect($transaction->fresh()->status)->toBe('paid');
});

it('refuses a webhook signed for a different body even when only whitespace differs', function () {
    [$transaction] = pmsOpenSession($this);
    $payload = pmsPaidEvent('evt_ws', 'cs_test_session_1', 'pay_ws', 235235);
    $signedRaw = json_encode($payload);

    $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => pmsSign($signedRaw),
    ], $signedRaw . ' ')->assertStatus(401);

    expect($transaction->fresh()->status)->toBe('pending');
});

it('refuses a replayed, correctly-signed delivery whose timestamp has expired', function () {
    [$transaction] = pmsOpenSession($this);
    $raw = json_encode(pmsPaidEvent('evt_old', 'cs_test_session_1', 'pay_old', 235235));

    $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => pmsSign($raw, 'te', time() - 3600),
    ], $raw)->assertStatus(401);

    expect($transaction->fresh()->status)->toBe('pending');
    expect(PaymentWebhookEvent::count())->toBe(0);
});

it('refuses a live-only signature on the endpoint, and a stale one, with no side effects', function () {
    [$transaction] = pmsOpenSession($this);
    $raw = json_encode(pmsPaidEvent('evt_li', 'cs_test_session_1', 'pay_li', 235235));

    $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => pmsSign($raw, 'li'),
    ], $raw)->assertStatus(401)->assertJsonPath('outcome', 'invalid_signature');

    expect($transaction->fresh()->status)->toBe('pending');
    expect(PaymentWebhookEvent::count())->toBe(0);
});

it('refuses an oversized body before hashing it', function () {
    pmsOpenSession($this);
    $raw = str_repeat('a', 1_000_001);

    $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => pmsSign($raw),
    ], $raw)->assertStatus(413);
});

// ───────────────────────── event envelope ─────────────────────────

it('matches a paid event to its transaction by session id AND the reference number we sent', function () {
    [$transaction] = pmsOpenSession($this);

    pmsWebhook($this, pmsPaidEvent('evt_ref', 'cs_test_session_1', 'pay_ref', 235235, reference: $transaction->reference))
        ->assertOk()->assertJsonPath('outcome', 'paid');

    expect($transaction->fresh()->status)->toBe('paid');
});

it('never calls a payment paid when the session\'s reference number is wrong or missing', function (?string $reference) {
    [$transaction] = pmsOpenSession($this);
    $payload = pmsPaidEvent('evt_badref', 'cs_test_session_1', 'pay_badref', 235235, reference: $reference ?? 'x');
    if ($reference === null) {
        unset($payload['data']['attributes']['data']['attributes']['reference_number']);
    }

    pmsWebhook($this, $payload)->assertOk()->assertJsonPath('outcome', 'review_reference_mismatch');

    $transaction->refresh();
    expect($transaction->status)->toBe('review');
    expect($transaction->flag)->toBe('reference_mismatch');
    expect(PaymentTransaction::where('status', 'paid')->count())->toBe(0);
})->with(['another order\'s reference' => ['TMP-SOMEONE-ELSE'], 'missing reference' => [null]]);

it('puts a payment in a currency other than PHP in review', function () {
    [$transaction] = pmsOpenSession($this);

    pmsWebhook($this, pmsPaidEvent('evt_usd', 'cs_test_session_1', 'pay_usd', 235235, currency: 'USD'))
        ->assertOk()->assertJsonPath('outcome', 'review_currency_mismatch');

    expect($transaction->fresh()->status)->toBe('review');
});

it('rejects events that break the documented envelope', function (string $break) {
    pmsOpenSession($this);
    $payload = pmsPaidEvent('evt_env', 'cs_test_session_1', 'pay_env', 235235);

    match ($break) {
        'event id without evt_ prefix' => $payload['data']['id'] = 'abc123',
        'event type not a string' => $payload['data']['attributes']['type'] = ['x'],
        'livemode not a boolean' => $payload['data']['attributes']['livemode'] = 'false',
        'resource missing' => $payload['data']['attributes']['data'] = 'nope',
        'no data wrapper' => $payload = ['id' => 'evt_env'],
    };

    pmsWebhook($this, $payload)->assertStatus(400)->assertJsonPath('outcome', 'malformed_event');

    expect(PaymentWebhookEvent::count())->toBe(0);
    expect(PaymentTransaction::where('status', 'paid')->count())->toBe(0);
})->with(['event id without evt_ prefix', 'event type not a string', 'livemode not a boolean', 'resource missing', 'no data wrapper']);

it('does not trust a paid payment whose id or amount is malformed', function (array $change) {
    [$transaction] = pmsOpenSession($this);
    $payload = pmsPaidEvent('evt_mal', 'cs_test_session_1', 'pay_ok', 235235);
    $payment = &$payload['data']['attributes']['data']['attributes']['payments'][0];
    $payment = array_replace_recursive($payment, $change);

    pmsWebhook($this, $payload)->assertOk()->assertJsonPath('outcome', 'no_paid_payment_in_event');

    expect($transaction->fresh()->status)->toBe('pending');
})->with([
    'payment id without pay_ prefix' => [['id' => 'abc']],
    'amount as a string' => [['attributes' => ['amount' => '235235']]],
    'amount as a float' => [['attributes' => ['amount' => 2352.35]]],
    'zero amount' => [['attributes' => ['amount' => 0]]],
]);

// ───────────────────────── Checkout Sessions v2 contract ─────────────────────────

it('sends the v2 create request exactly as PayMongo documents it', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    pmsFakeGateway();
    config(['paymongo.success_url' => 'https://example.test/paid', 'paymongo.cancel_url' => 'https://example.test/cancel']);
    $booking = pmsBookingFor($user);

    pmsCheckout($this, $user, $booking)->assertCreated();
    $reference = PaymentTransaction::firstOrFail()->reference;

    Http::assertSent(function ($request) use ($reference, $booking) {
        $attributes = $request['data']['attributes'] ?? [];

        return $request->method() === 'POST'
            && $request->url() === 'https://api.paymongo.com/v2/checkout_sessions'
            // HTTP Basic: the secret key is the username, the password is empty.
            && $request->header('Authorization')[0] === 'Basic ' . base64_encode(PMS_SECRET_KEY . ':')
            && $request->isJson()
            && array_keys($request->data()) === ['data']
            && $attributes['line_items'] === [[
                'name' => 'TowMate towing service',
                'description' => 'Towing service ' . $booking->booking_code,
                'amount' => 235235,       // ₱2,352.35 in centavos
                'currency' => 'PHP',
                'quantity' => 1,
            ]]
            && $attributes['payment_method_types'] === ['gcash']
            && $attributes['reference_number'] === $reference
            && $attributes['metadata']['payment_transaction'] === $reference
            && $attributes['success_url'] === 'https://example.test/paid'
            && $attributes['cancel_url'] === 'https://example.test/cancel'
            && $attributes['send_email_receipt'] === false
            // Nothing the customer typed, and no payment credentials, ever go to the provider.
            && ! str_contains(json_encode($request->data()), 'gcash_number')
            && ! str_contains(json_encode($request->data()), PMS_WEBHOOK_SECRET);
    });
});

it('returns the customer to the verified app link when no explicit return URLs are configured', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    pmsFakeGateway();
    $booking = pmsBookingFor($user);

    pmsCheckout($this, $user, $booking)->assertCreated();

    Http::assertSent(fn ($request) => $request['data']['attributes']['success_url'] === 'https://www.jarztowing.com/pay/return/success?booking=' . $booking->booking_code
        && $request['data']['attributes']['cancel_url'] === 'https://www.jarztowing.com/pay/return/cancelled?booking=' . $booking->booking_code);
});

it('reads the create response in either documented shape', function (array $data) {
    $user = pmsCustomerUser();
    pmsEnable($user);
    pmsFakeGateway(['create' => Http::response(['data' => $data], 200)]);

    pmsCheckout($this, $user, pmsBookingFor($user))->assertCreated()
        ->assertJsonPath('data.checkout_url', 'https://checkout.paymongo.test/abc');

    expect(PaymentTransaction::firstOrFail()->provider_checkout_session_id)->toBe('cs_shape_1');
})->with([
    'API reference: fields under attributes' => [['id' => 'cs_shape_1', 'type' => 'checkout_session', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.test/abc', 'livemode' => false]]],
    'quick start: fields beside the id' => [['id' => 'cs_shape_1', 'type' => 'checkout_session', 'checkout_url' => 'https://checkout.paymongo.test/abc', 'livemode' => false]],
]);

it('refuses a create response it cannot trust and never hands out the link', function (array $body) {
    $user = pmsCustomerUser();
    pmsEnable($user);
    pmsFakeGateway(['create' => Http::response($body, 200)]);

    pmsCheckout($this, $user, pmsBookingFor($user))->assertStatus(502);

    $transaction = PaymentTransaction::firstOrFail();
    expect($transaction->status)->toBe('failed');
    expect($transaction->failure_code)->toBe('provider_unexpected_response');
    expect($transaction->checkout_url)->toBeNull();
    expect($transaction->provider_checkout_session_id)->toBeNull();
})->with([
    'link on an unrelated host' => [['data' => ['id' => 'cs_x1', 'attributes' => ['checkout_url' => 'https://evil.example/pay', 'livemode' => false]]]],
    'look-alike host' => [['data' => ['id' => 'cs_x1', 'attributes' => ['checkout_url' => 'https://paymongo.test.evil.example/pay', 'livemode' => false]]]],
    'plain http' => [['data' => ['id' => 'cs_x1', 'attributes' => ['checkout_url' => 'http://checkout.paymongo.test/x', 'livemode' => false]]]],
    'credentials in the url' => [['data' => ['id' => 'cs_x1', 'attributes' => ['checkout_url' => 'https://user:pw@checkout.paymongo.test/x', 'livemode' => false]]]],
    'odd port' => [['data' => ['id' => 'cs_x1', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.test:8443/x', 'livemode' => false]]]],
    'javascript scheme' => [['data' => ['id' => 'cs_x1', 'attributes' => ['checkout_url' => 'javascript:alert(1)', 'livemode' => false]]]],
    'session id of the wrong shape' => [['data' => ['id' => 'pay_123', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.test/x', 'livemode' => false]]]],
    'no livemode flag to confirm test mode' => [['data' => ['id' => 'cs_x1', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.test/x']]]],
    'no data' => [['unexpected' => true]],
    'empty body' => [[]],
]);

it('accepts a checkout link on a subdomain of an allowed PayMongo host only', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    config(['paymongo.checkout_url_hosts' => ['paymongo.com']]);
    pmsFakeGateway(['create' => Http::response(['data' => ['id' => 'cs_host_1', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_host_1', 'livemode' => false]]], 200)]);

    pmsCheckout($this, $user, pmsBookingFor($user))->assertCreated();
});

it('turns each kind of failed API response into a coarse, safe failure code', function (int $status, string $code) {
    $user = pmsCustomerUser();
    pmsEnable($user);
    pmsFakeGateway(['create' => Http::response(['errors' => [['code' => 'whatever', 'detail' => 'sk_test_fake_key_for_unit_tests']]], $status)]);

    $response = pmsCheckout($this, $user, pmsBookingFor($user))->assertStatus(502);

    // Our coarse category, plus the provider's identifier-shaped error code (never its wording) for diagnosis.
    expect(PaymentTransaction::firstOrFail()->failure_code)->toBe($code . ':whatever');
    expect($response->getContent())->not->toContain('sk_test_')->not->toContain('whatever');
})->with([
    'bad key' => [401, 'provider_auth'],
    'forbidden' => [403, 'provider_auth'],
    'rate limited' => [429, 'provider_rate_limited'],
    'invalid request' => [400, 'provider_rejected'],
    'unprocessable' => [422, 'provider_rejected'],
    'provider error' => [500, 'provider_unavailable'],
    'unavailable' => [503, 'provider_unavailable'],
]);

it('reads a retrieved session and its payments, and ignores nesting it does not know', function () {
    [$transaction] = pmsOpenSession($this);
    $transaction->update(['expires_at' => now()->subMinute()]);
    pmsFakeGateway(['retrieve' => Http::response(['data' => ['id' => 'cs_test_session_1', 'attributes' => [
        'status' => 'active', 'livemode' => false, 'reference_number' => $transaction->reference,
        'payments' => [['id' => 'pay_ret', 'attributes' => ['status' => 'paid', 'amount' => 235235, 'currency' => 'PHP']]],
    ]]], 200)]);

    app(OnlinePaymentService::class)->reconcile();

    expect($transaction->fresh()->status)->toBe('paid');
    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://api.paymongo.com/v1/checkout_sessions/cs_test_session_1');
});

it('does not trust a retrieved session whose reference number belongs to another order', function () {
    [$transaction] = pmsOpenSession($this);
    $transaction->update(['expires_at' => now()->subMinute()]);
    pmsFakeGateway(['retrieve' => Http::response(['data' => ['id' => 'cs_test_session_1', 'attributes' => [
        'status' => 'active', 'livemode' => false, 'reference_number' => 'TMP-OTHER',
        'payments' => [['id' => 'pay_ret2', 'attributes' => ['status' => 'paid', 'amount' => 235235, 'currency' => 'PHP']]],
    ]]], 200)]);

    app(OnlinePaymentService::class)->reconcile();

    expect($transaction->fresh()->status)->toBe('review');
    expect($transaction->fresh()->flag)->toBe('reference_mismatch');
});

it('refuses to treat a live-mode retrieved session as evidence', function () {
    [$transaction] = pmsOpenSession($this);
    $transaction->update(['expires_at' => now()->subMinute()]);
    pmsFakeGateway(['retrieve' => Http::response(['data' => ['id' => 'cs_test_session_1', 'attributes' => [
        'status' => 'active', 'livemode' => true,
        'payments' => [['id' => 'pay_live', 'attributes' => ['status' => 'paid', 'amount' => 235235]]],
    ]]], 200)]);

    expect(app(OnlinePaymentService::class)->reconcile()['unconfirmed'])->toBe(1);
    expect($transaction->fresh()->status)->toBe('pending');
});

// ───────────────────────── readiness commands ─────────────────────────

it('keeps only an identifier-shaped provider error code, never anything that could be a credential', function (string $providerCode, string $expected) {
    $user = pmsCustomerUser();
    pmsEnable($user);
    pmsFakeGateway(['create' => Http::response(['errors' => [['code' => $providerCode, 'detail' => 'ignored']]], 400)]);

    pmsCheckout($this, $user, pmsBookingFor($user))->assertStatus(502);

    expect(PaymentTransaction::firstOrFail()->failure_code)->toBe($expected);
})->with([
    'a normal capability-style code' => ['payment_method_unavailable', 'provider_rejected:payment_method_unavailable'],
    'looks like a secret key' => ['sk_test_abcdef123456', 'provider_rejected'],
    'looks like a webhook secret' => ['whsec_abcdef123456', 'provider_rejected'],
    'too long' => [str_repeat('a', 31), 'provider_rejected'],
    'has punctuation' => ['bad code!', 'provider_rejected'],
]);

/** A fully prepared sandbox: flag on, test key, webhook secret, an existing allow-listed customer with a payable booking. */
function pmsReadyForPilot(): array
{
    $user = pmsCustomerUser();
    pmsEnable($user); // allow-listed first, so the booking is created as a demo booking
    $booking = pmsBookingFor($user);
    config(['app.url' => 'https://pilot-test.example.com']);

    return [$user, $booking];
}

it('reports readiness without ever printing a secret value', function () {
    pmsReadyForPilot();
    config(['paymongo.secret_key' => 'sk_test_very_secret_value_1234', 'paymongo.webhook_secret' => 'whsec_very_secret_value_5678']);

    $this->artisan('online-payments:check')
        ->expectsOutputToContain('Ready for a controlled sandbox pilot')
        ->expectsOutputToContain('https://pilot-test.example.com/api/webhooks/paymongo')
        ->doesntExpectOutputToContain('very_secret_value')
        ->assertSuccessful();
});

it('names the exact webhook URL to register and warns when APP_URL is not a public https domain', function (string $appUrl) {
    pmsReadyForPilot();
    config(['app.url' => $appUrl]);

    $this->artisan('online-payments:check')
        ->expectsOutputToContain(rtrim($appUrl, '/') . '/api/webhooks/paymongo')
        ->expectsOutputToContain('public https:// webhook URL')
        ->assertFailed();
})->with(['http://localhost:8000', 'http://127.0.0.1:8000', 'https://127.0.0.1', 'http://my-tunnel.example.com', 'https://localhost']);

it('is not ready until the allow-listed customer really exists and has a payable booking', function () {
    [$user, $booking] = pmsReadyForPilot();
    $booking->update(['status' => 'in_progress']);

    $this->artisan('online-payments:check')
        ->expectsOutputToContain('no synthetic booking at arrived_dropoff')
        ->assertFailed();

    config(['paymongo.test_user_ids' => [999999]]);
    $this->artisan('online-payments:check')
        ->expectsOutputToContain('None of the allow-listed test customers exist')
        ->assertFailed();
});

it('treats a database that is not clearly isolated as a blocker', function () {
    pmsReadyForPilot();
    config(['database.connections.pgsql.database' => 'towmate_jarz']); // the shared development database name

    $this->artisan('online-payments:check')
        ->expectsOutputToContain('Never point the pilot at the shared development or any production database')
        ->assertFailed();
});

it('creates a synthetic payable fixture on an isolated database and nothing else', function () {
    $bookingsBefore = Booking::count();

    $this->artisan('online-payments:make-pilot-fixture', ['--amount' => '100.00'])
        ->expectsOutputToContain('Synthetic pilot fixture created')
        ->expectsOutputToContain('10000 centavos')
        ->assertSuccessful();

    $booking = Booking::latest('id')->firstOrFail();
    expect(Booking::count())->toBe($bookingsBefore + 1);
    expect($booking->status)->toBe('arrived_dropoff');
    expect((float) $booking->final_total)->toBe(100.0);
    expect($booking->payment_method)->toBeNull();
    expect($booking->payment_submitted_at)->toBeNull();
    expect($booking->preferred_payment_method)->toBe('gcash');
    expect($booking->pickup_address)->toContain('PILOT TEST');
    expect(app(\App\Services\OnlinePayments\PayableResolver::class)->resolve($booking)->totalCentavos)->toBe(10000);
    expect(PaymentTransaction::count())->toBe(0); // the fixture opens no checkout

    // Running it again reuses the same dedicated test customer.
    $this->artisan('online-payments:make-pilot-fixture')->assertSuccessful();
    expect(User::where('email', 'pilot.customer@gmail.com')->count())->toBe(1);
});

it('lets the fixture customer, once allow-listed, open a checkout through the normal path', function () {
    $this->artisan('online-payments:make-pilot-fixture', ['--amount' => '250.50'])->assertSuccessful();
    $booking = Booking::latest('id')->firstOrFail();
    $user = User::findOrFail($booking->customer->user_id);
    pmsEnable($user);
    pmsFakeGateway();

    pmsCheckout($this, $user, $booking)->assertCreated()->assertJsonPath('data.amount', '250.50');

    expect(PaymentTransaction::firstOrFail()->amount_centavos)->toBe(25050);
});

it('refuses to create the fixture anywhere but an isolated database, before touching it', function (string $database) {
    config(['database.connections.pgsql.database' => $database]);
    $bookings = Booking::count();
    $users = User::count();

    $this->artisan('online-payments:make-pilot-fixture')
        ->expectsOutputToContain('Refusing to write')
        ->assertFailed();

    expect(Booking::count())->toBe($bookings)->and(User::count())->toBe($users);
})->with(['towmate_jarz', 'towmate_production', 'railway', 'towmate_jarz_testing_backup']);

it('refuses a fixture amount outside the safe range or a bad email', function (array $options) {
    $this->artisan('online-payments:make-pilot-fixture', $options)->assertFailed();

    expect(Booking::count())->toBe(0);
})->with([
    'zero' => [['--amount' => '0']],
    'too big' => [['--amount' => '5000']],
    'not a number' => [['--amount' => 'abc']],
    'three decimals' => [['--amount' => '10.123']],
    'bad email' => [['--email' => 'not-an-email']],
]);

it('refuses the pilot checkout command on a database that is not clearly isolated', function () {
    [$user, $booking] = pmsReadyForPilot();
    config(['database.connections.pgsql.database' => 'towmate_jarz']);
    Http::fake();

    $this->artisan('online-payments:pilot-checkout', ['booking_code' => $booking->booking_code, '--user' => $user->id, '--confirm-sandbox' => true])
        ->expectsOutputToContain('not a clearly isolated')
        ->assertFailed();

    Http::assertNothingSent();
});

it('shows the operator the recorded failure code when the pilot checkout is rejected by the provider', function () {
    [$user, $booking] = pmsReadyForPilot();
    pmsFakeGateway(['create' => Http::response(['errors' => [['code' => 'payment_method_unavailable']]], 400)]);

    $this->artisan('online-payments:pilot-checkout', ['booking_code' => $booking->booking_code, '--user' => $user->id, '--confirm-sandbox' => true])
        ->expectsOutputToContain('Recorded failure: provider_rejected:payment_method_unavailable')
        ->assertFailed();
});

it('lists what is missing and fails when the sandbox is not configured', function () {
    $this->artisan('online-payments:check')
        ->expectsOutputToContain('Not ready')
        ->expectsOutputToContain('PAYMONGO_SANDBOX_ENABLED')
        ->assertFailed();
});

it('calls a live key a blocker without echoing it', function () {
    pmsReadyForPilot();
    config(['paymongo.secret_key' => 'sk_live_must_not_appear_9999']);

    $this->artisan('online-payments:check')
        ->expectsOutputToContain('LIVE (rejected)')
        ->doesntExpectOutputToContain('must_not_appear')
        ->assertFailed();
});

it('refuses to open a pilot checkout without explicit confirmation or a complete test setup', function () {
    $user = pmsCustomerUser();
    $booking = pmsBookingFor($user);
    Http::fake();

    $this->artisan('online-payments:pilot-checkout', ['booking_code' => $booking->booking_code, '--user' => $user->id])->assertFailed();
    $this->artisan('online-payments:pilot-checkout', ['booking_code' => $booking->booking_code, '--user' => $user->id, '--confirm-sandbox' => true])->assertFailed();

    pmsEnable($user);
    config(['paymongo.secret_key' => 'sk_live_nope']);
    $this->artisan('online-payments:pilot-checkout', ['booking_code' => $booking->booking_code, '--user' => $user->id, '--confirm-sandbox' => true])->assertFailed();

    Http::assertNothingSent();
    expect(PaymentTransaction::count())->toBe(0);
});

it('opens a pilot checkout through the same guarded path when confirmed and fully configured', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    pmsFakeGateway();
    $booking = pmsBookingFor($user);

    $this->artisan('online-payments:pilot-checkout', ['booking_code' => $booking->booking_code, '--user' => $user->id, '--confirm-sandbox' => true])
        ->expectsOutputToContain('Test checkout created')
        ->doesntExpectOutputToContain(PMS_SECRET_KEY)
        ->assertSuccessful();

    expect(PaymentTransaction::where('status', 'pending')->count())->toBe(1);
    expect($booking->fresh()->status)->toBe('arrived_dropoff');
});

it('will not run the pilot for a customer who is not on the test allow-list', function () {
    $tester = pmsCustomerUser();
    $stranger = pmsCustomerUser();
    pmsEnable($tester);
    Http::fake();
    $booking = pmsBookingFor($stranger);

    $this->artisan('online-payments:pilot-checkout', ['booking_code' => $booking->booking_code, '--user' => $stranger->id, '--confirm-sandbox' => true])->assertFailed();

    Http::assertNothingSent();
});

it('accepts a timestamped-signature delivery end to end', function () {
    [$transaction] = pmsOpenSession($this);
    $payload = pmsPaidEvent('evt_ts', 'cs_test_session_1', 'pay_ts', 235235);

    pmsWebhook($this, $payload, format: 'te')->assertOk()->assertJsonPath('outcome', 'paid');

    expect($transaction->fresh()->status)->toBe('paid');
});

it('rejects live-mode events even when correctly signed', function () {
    [$transaction] = pmsOpenSession($this);

    pmsWebhook($this, pmsPaidEvent('evt_live', 'cs_test_session_1', 'pay_live', 235235, livemode: true))
        ->assertStatus(400)
        ->assertJsonPath('outcome', 'live_event_rejected');

    expect($transaction->fresh()->status)->toBe('pending');
    expect(PaymentWebhookEvent::count())->toBe(0);
});

it('does not answer a webhook when the webhook secret is not configured', function () {
    [$transaction] = pmsOpenSession($this);
    config(['paymongo.webhook_secret' => '']);

    pmsWebhook($this, pmsPaidEvent('evt_x', 'cs_test_session_1', 'pay_x', 235235), pmsSign('x'))->assertStatus(503);

    expect($transaction->fresh()->status)->toBe('pending');
});

it('answers malformed signed events with 400 and stores nothing', function () {
    pmsOpenSession($this);
    $raw = json_encode(['data' => ['id' => 'evt_m']]);

    pmsWebhook($this, ['data' => ['id' => 'evt_m']], pmsSign($raw))->assertStatus(400);

    expect(PaymentWebhookEvent::count())->toBe(0);
});

// ───────────────────────── webhook: payment outcomes ─────────────────────────

it('marks a transaction paid only from a verified paid event, and never touches the booking', function () {
    [$transaction, $booking] = pmsOpenSession($this);

    pmsWebhook($this, pmsPaidEvent('evt_ok', 'cs_test_session_1', 'pay_ok', 235235))
        ->assertOk()->assertJsonPath('outcome', 'paid');

    $transaction->refresh();
    expect($transaction->status)->toBe('paid');
    expect($transaction->provider_payment_id)->toBe('pay_ok');
    expect($transaction->paid_at)->not->toBeNull();
    expect($transaction->flag)->toBeNull();

    // A payment webhook must never complete a job or record a collected method.
    $booking->refresh();
    expect($booking->status)->toBe('arrived_dropoff');
    expect($booking->payment_method)->toBeNull();
    expect($booking->payment_submitted_at)->toBeNull();
    expect($booking->completed_at)->toBeNull();
    expect($booking->preferred_payment_method)->toBe('gcash');
});

it('does not treat an event without a paid payment as proof', function (string $status) {
    [$transaction] = pmsOpenSession($this);

    pmsWebhook($this, pmsPaidEvent('evt_np', 'cs_test_session_1', 'pay_np', 235235, $status))
        ->assertOk()->assertJsonPath('outcome', 'no_paid_payment_in_event');

    expect($transaction->fresh()->status)->toBe('pending');
})->with(['pending', 'failed', 'awaiting_next_action']);

it('has no redirect-based way to mark a payment paid', function () {
    [$transaction, $booking, $user] = pmsOpenSession($this);

    // The customer coming back from the hosted page (or hitting any status route) proves nothing.
    Sanctum::actingAs($user, ['*']);
    $this->getJson("/api/v1/bookings/{$booking->booking_code}/online-payment")->assertOk()->assertJsonPath('data.status', 'pending');
    $this->get('/api/webhooks/paymongo')->assertStatus(405);

    expect($transaction->fresh()->status)->toBe('pending');
    $paths = collect(app('router')->getRoutes()->getRoutes())->map->uri()->filter(fn ($u) => str_contains($u, 'paymongo') || str_contains($u, 'online-payment'));
    expect($paths->contains(fn ($u) => preg_match('#success|return|callback#i', $u)))->toBeFalse();
});

it('ignores a replayed event: one event row, one paid transaction', function () {
    [$transaction] = pmsOpenSession($this);
    $payload = pmsPaidEvent('evt_replay', 'cs_test_session_1', 'pay_replay', 235235);

    pmsWebhook($this, $payload)->assertOk()->assertJsonPath('outcome', 'paid');
    $paidAt = $transaction->fresh()->paid_at;

    pmsWebhook($this, $payload)->assertOk()->assertJsonPath('outcome', 'replay');
    pmsWebhook($this, $payload)->assertOk()->assertJsonPath('outcome', 'replay');

    expect(PaymentWebhookEvent::where('provider_event_id', 'evt_replay')->count())->toBe(1);
    expect(PaymentTransaction::where('status', 'paid')->count())->toBe(1);
    expect($transaction->fresh()->paid_at->equalTo($paidAt))->toBeTrue();
});

it('does not double-record the same payment delivered under a new event id', function () {
    [$transaction] = pmsOpenSession($this);

    pmsWebhook($this, pmsPaidEvent('evt_one', 'cs_test_session_1', 'pay_same', 235235))->assertJsonPath('outcome', 'paid');
    pmsWebhook($this, pmsPaidEvent('evt_two', 'cs_test_session_1', 'pay_same', 235235))->assertJsonPath('outcome', 'already_paid');

    expect(PaymentTransaction::where('status', 'paid')->count())->toBe(1);
    expect(PaymentWebhookEvent::count())->toBe(2);
});

it('flags a different payment arriving on an already-paid session instead of recording it', function () {
    [$transaction] = pmsOpenSession($this);

    pmsWebhook($this, pmsPaidEvent('evt_a', 'cs_test_session_1', 'pay_a', 235235))->assertJsonPath('outcome', 'paid');
    pmsWebhook($this, pmsPaidEvent('evt_b', 'cs_test_session_1', 'pay_b', 235235))->assertJsonPath('outcome', 'flagged_second_payment');

    $transaction->refresh();
    expect($transaction->provider_payment_id)->toBe('pay_a');
    expect($transaction->flag)->toBe('second_payment_same_session');
    expect(PaymentTransaction::where('status', 'paid')->count())->toBe(1);
});

it('files a second session\'s payment for an already-paid payable as a duplicate for review', function () {
    [$first, $booking, $user] = pmsOpenSession($this);
    pmsWebhook($this, pmsPaidEvent('evt_p1', 'cs_test_session_1', 'pay_p1', 235235))->assertJsonPath('outcome', 'paid');

    $second = PaymentTransaction::create([
        'reference' => 'TMP-SECOND', 'payable_type' => 'booking', 'payable_id' => $booking->id,
        'booking_id' => $booking->id, 'customer_id' => $user->customer->id, 'method' => 'gcash',
        'amount_centavos' => 235235, 'status' => 'pending', 'provider_checkout_session_id' => 'cs_second',
        'expires_at' => now()->addMinutes(10),
    ]);

    pmsWebhook($this, pmsPaidEvent('evt_p2', 'cs_second', 'pay_p2', 235235))->assertJsonPath('outcome', 'duplicate_payment');

    expect($second->fresh()->status)->toBe('duplicate');
    expect($second->fresh()->flag)->toBe('duplicate_payment');
    expect(PaymentTransaction::where('status', 'paid')->count())->toBe(1);
    expect($first->fresh()->status)->toBe('paid');
});

it('puts a wrong-amount payment in review instead of marking it paid', function () {
    [$transaction] = pmsOpenSession($this);

    pmsWebhook($this, pmsPaidEvent('evt_amt', 'cs_test_session_1', 'pay_amt', 100))
        ->assertOk()->assertJsonPath('outcome', 'review_amount_mismatch');

    $transaction->refresh();
    expect($transaction->status)->toBe('review');
    expect($transaction->flag)->toBe('amount_mismatch');
    expect(PaymentTransaction::where('status', 'paid')->count())->toBe(0);
});

it('puts a payment in review when the booking total changed after checkout began', function () {
    [$transaction, $booking] = pmsOpenSession($this);
    $booking->update(['final_total' => 2500.00]); // the dispatcher re-priced mid-checkout

    pmsWebhook($this, pmsPaidEvent('evt_sup', 'cs_test_session_1', 'pay_sup', 235235))
        ->assertOk()->assertJsonPath('outcome', 'review_superseded_total');

    expect($transaction->fresh()->status)->toBe('review');
    expect($transaction->fresh()->flag)->toBe('superseded_total');
});

it('replaces an open session whose amount no longer matches the booking', function () {
    [$old, $booking, $user] = pmsOpenSession($this);
    $booking->update(['final_total' => 2500.00]);
    pmsFakeGateway(['session_id' => 'cs_test_session_2']);

    pmsCheckout($this, $user, $booking)->assertCreated();

    expect($old->fresh()->status)->toBe('expired');
    expect($old->fresh()->flag)->toBe('superseded_total');
    $new = PaymentTransaction::where('status', 'pending')->firstOrFail();
    expect($new->amount_centavos)->toBe(250000);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/cs_test_session_1/expire'));
});

it('puts a payment for a cancelled booking in review', function () {
    [$transaction, $booking] = pmsOpenSession($this);
    $booking->update(['status' => 'cancelled']);

    pmsWebhook($this, pmsPaidEvent('evt_can', 'cs_test_session_1', 'pay_can', 235235))
        ->assertOk()->assertJsonPath('outcome', 'review_booking_cancelled');

    expect($transaction->fresh()->status)->toBe('review');
});

it('flags online money that arrives after cash was already recorded, for refund review only', function () {
    [$transaction, $booking] = pmsOpenSession($this);
    $booking->update(['payment_method' => 'cash', 'cash_received' => 2400, 'payment_submitted_at' => now()]);

    pmsWebhook($this, pmsPaidEvent('evt_cash', 'cs_test_session_1', 'pay_cash', 235235))->assertOk()->assertJsonPath('outcome', 'paid');

    $transaction->refresh();
    expect($transaction->status)->toBe('paid');
    expect($transaction->flag)->toBe('cash_also_collected');
    expect(PaymentTransaction::dispatcherNote($booking->fresh()))->toContain('review for a refund');
    // No automatic refund call of any kind.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'refund'));
});

it('records a failed payment attempt without closing the session', function () {
    [$transaction] = pmsOpenSession($this);

    $payload = ['data' => ['id' => 'evt_fail', 'type' => 'event', 'attributes' => [
        'type' => 'payment.failed', 'livemode' => false,
        'data' => ['id' => 'pay_failed', 'attributes' => [
            'failed_code' => 'processor_blocked',
            'metadata' => ['payment_transaction' => $transaction->reference],
        ]],
    ]]];

    pmsWebhook($this, $payload)->assertOk()->assertJsonPath('outcome', 'failure_recorded');

    $transaction->refresh();
    expect($transaction->status)->toBe('pending'); // the customer may retry on the same hosted page
    expect($transaction->failure_code)->toBe('processor_blocked');
    expect(PaymentTransaction::where('status', 'paid')->count())->toBe(0);
});

it('ignores unknown event types and unknown sessions politely', function () {
    pmsOpenSession($this);

    $other = ['data' => ['id' => 'evt_other', 'type' => 'event', 'attributes' => ['type' => 'source.chargeable', 'livemode' => false, 'data' => ['id' => 'x']]]];
    pmsWebhook($this, $other)->assertOk()->assertJsonPath('outcome', 'ignored_event_type');
    pmsWebhook($this, pmsPaidEvent('evt_unk', 'cs_unknown', 'pay_unk', 100))->assertOk()->assertJsonPath('outcome', 'unknown_session');

    expect(PaymentTransaction::where('status', 'paid')->count())->toBe(0);
});

// ───────────────────────── cancel / expire / reconcile ─────────────────────────

it('cancels an open checkout only once the provider has expired it', function () {
    [$transaction, $booking, $user] = pmsOpenSession($this);

    Sanctum::actingAs($user, ['*']);
    $this->postJson("/api/v1/bookings/{$booking->booking_code}/online-payment/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect($transaction->fresh()->status)->toBe('cancelled');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/cs_test_session_1/expire'));

    // The finished session no longer exposes a link.
    $this->getJson("/api/v1/bookings/{$booking->booking_code}/online-payment")->assertOk()->assertJsonPath('data.checkout_url', null);
});

it('does not claim cancelled when the provider cannot confirm it', function () {
    [$transaction, $booking, $user] = pmsOpenSession($this);
    pmsFakeGateway([
        'expire' => Http::response(['errors' => [['code' => 'server_error']]], 500),
        'retrieve' => Http::response(['errors' => []], 500),
    ]);

    Sanctum::actingAs($user, ['*']);
    $this->postJson("/api/v1/bookings/{$booking->booking_code}/online-payment/cancel")->assertStatus(409)->assertJsonPath('reason', 'cannot_cancel');

    expect($transaction->fresh()->status)->toBe('pending');
});

it('finds a payment that landed while the customer was cancelling', function () {
    [$transaction, $booking, $user] = pmsOpenSession($this);
    pmsFakeGateway([
        'expire' => Http::response(['errors' => [['code' => 'resource_invalid']]], 400),
        'retrieve' => Http::response(['data' => ['id' => 'cs_test_session_1', 'attributes' => [
            'status' => 'active', 'livemode' => false,
            'payments' => [['id' => 'pay_race', 'attributes' => ['status' => 'paid', 'amount' => 235235]]],
        ]]], 200),
    ]);

    Sanctum::actingAs($user, ['*']);
    $this->postJson("/api/v1/bookings/{$booking->booking_code}/online-payment/cancel")->assertOk()->assertJsonPath('data.status', 'paid');

    expect($transaction->fresh()->status)->toBe('paid');
});

it('files a payment that arrives after the checkout was closed as review', function () {
    [$transaction, $booking, $user] = pmsOpenSession($this);
    Sanctum::actingAs($user, ['*']);
    $this->postJson("/api/v1/bookings/{$booking->booking_code}/online-payment/cancel")->assertOk();

    pmsWebhook($this, pmsPaidEvent('evt_late', 'cs_test_session_1', 'pay_late', 235235))
        ->assertOk()->assertJsonPath('outcome', 'review_paid_after_close');

    expect($transaction->fresh()->status)->toBe('review');
    expect($transaction->fresh()->flag)->toBe('paid_after_close');
});

it('reconciliation expires a timed-out session the provider reports unpaid', function () {
    [$transaction] = pmsOpenSession($this);
    $transaction->update(['expires_at' => now()->subMinute()]);

    $summary = app(OnlinePaymentService::class)->reconcile();

    expect($summary)->toMatchArray(['checked' => 1, 'expired' => 1, 'paid' => 0]);
    expect($transaction->fresh()->status)->toBe('expired');
});

it('reconciliation recovers a payment whose webhook never arrived', function () {
    [$transaction] = pmsOpenSession($this);
    $transaction->update(['expires_at' => now()->subMinute()]);
    pmsFakeGateway(['retrieve' => Http::response(['data' => ['id' => 'cs_test_session_1', 'attributes' => [
        'status' => 'active', 'livemode' => false,
        'payments' => [['id' => 'pay_missed', 'attributes' => ['status' => 'paid', 'amount' => 235235]]],
    ]]], 200)]);

    $summary = app(OnlinePaymentService::class)->reconcile();

    expect($summary['paid'])->toBe(1);
    expect($transaction->fresh()->status)->toBe('paid');
    expect($transaction->fresh()->provider_payment_id)->toBe('pay_missed');
});

it('reconciliation leaves a session open when the provider cannot be asked', function () {
    [$transaction] = pmsOpenSession($this);
    $transaction->update(['expires_at' => now()->subMinute()]);
    pmsFakeGateway([
        'retrieve' => Http::response([], 500),
        'expire' => Http::response([], 500),
    ]);

    $summary = app(OnlinePaymentService::class)->reconcile();

    expect($summary['unconfirmed'])->toBe(1);
    expect($transaction->fresh()->status)->toBe('pending');
});

it('reconciliation never runs while the feature is off', function () {
    [$transaction] = pmsOpenSession($this);
    $transaction->update(['expires_at' => now()->subMinute()]);
    config(['paymongo.enabled' => false]);
    Http::fake();

    expect(app(OnlinePaymentService::class)->reconcile())->toBe(['checked' => 0, 'paid' => 0, 'expired' => 0, 'unconfirmed' => 0]);
    Http::assertNothingSent();
    $this->artisan('online-payments:reconcile')->assertSuccessful();
});

it('replaces a timed-out session on the next checkout request', function () {
    [$old, $booking, $user] = pmsOpenSession($this);
    $old->update(['expires_at' => now()->subMinute()]);
    pmsFakeGateway(['session_id' => 'cs_test_session_9']);

    pmsCheckout($this, $user, $booking)->assertCreated();

    expect($old->fresh()->status)->toBe('expired');
    expect(PaymentTransaction::where('status', 'pending')->count())->toBe(1);
});

it('does not allow a new checkout once the booking is paid online or under review', function (string $status) {
    [$transaction, $booking, $user] = pmsOpenSession($this);
    $transaction->update(['status' => $status]);

    pmsCheckout($this, $user, $booking)->assertStatus(409);

    expect(PaymentTransaction::count())->toBe(1);
})->with(['paid', 'review', 'duplicate']);

// ───────────────────────── groups ─────────────────────────

it('charges a normalized group once, for the active vehicles plus the quotation adjustment', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    pmsFakeGateway();
    [$first, $second] = pmsGroupFor($user);

    // Paying from the second vehicle's booking still opens ONE group session anchored on the quotation's source booking.
    pmsCheckout($this, $user, $second)->assertCreated();
    pmsCheckout($this, $user, $first)->assertOk();

    $transaction = PaymentTransaction::firstOrFail();
    expect(PaymentTransaction::count())->toBe(1);
    expect($transaction->payable_type)->toBe('group');
    expect($transaction->payable_id)->toBe($first->id);
    expect($transaction->amount_centavos)->toBe(340235); // 2352.35 + 1000.00 + 100 - 50
    expect($transaction->payable_snapshot['member_ids'])->toBe([$first->id, $second->id]);
    Http::assertSentCount(1);
});

it('leaves a cancelled vehicle out of the group total and refuses payment while any active one is not at the drop-off', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    pmsFakeGateway();
    [$first, $second] = pmsGroupFor($user);

    $second->update(['status' => 'in_progress']);
    pmsCheckout($this, $user, $first)->assertStatus(409)->assertJsonPath('reason', 'not_ready');

    $second->update(['status' => 'cancelled']);
    pmsCheckout($this, $user, $first)->assertCreated();

    expect(PaymentTransaction::firstOrFail()->amount_centavos)->toBe(240235); // 2352.35 + 100 - 50
});

it('treats a group payment as covering every vehicle for the dispatcher', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    pmsFakeGateway();
    [$first, $second] = pmsGroupFor($user);
    pmsCheckout($this, $user, $first)->assertCreated();

    pmsWebhook($this, pmsPaidEvent('evt_grp', 'cs_test_session_1', 'pay_grp', 340235))->assertJsonPath('outcome', 'paid');

    expect(PaymentTransaction::verifiedPaidFor($second)?->payable_type)->toBe('group');
    expect(PaymentTransaction::dispatcherNote($second))->toContain('Paid online (sandbox) P3,402.35');
    foreach ([$first, $second] as $booking) {
        $booking->refresh();
        expect($booking->status)->toBe('arrived_dropoff');
        expect($booking->payment_method)->toBeNull();
    }
});

it('keeps unrelated bookings of the same customer as separate payables', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    pmsFakeGateway();
    $one = pmsBookingFor($user);
    $two = pmsBookingFor($user);

    pmsCheckout($this, $user, $one)->assertCreated();
    pmsCheckout($this, $user, $two)->assertCreated();

    expect(PaymentTransaction::count())->toBe(2);
});

// ───────────────────────── interaction with the existing flows ─────────────────────────

it('leaves the Team Leader signature, proof and dispatcher confirmation fully in charge', function () {
    Mail::fake();
    pmsRole(3, 'Team Leader');
    pmsRole(2, 'Dispatcher');
    $teamLeader = User::factory()->create(['role_id' => 3, 'must_change_password' => false]);
    $dispatcher = User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
    [$transaction, $booking, $user] = pmsOpenSession($this);

    $unit = Unit::create([
        'name' => 'PMS Unit', 'plate_number' => 'PMS-' . random_int(1000, 9999),
        'truck_type_id' => $booking->truck_type_id, 'team_leader_id' => $teamLeader->id, 'status' => 'available',
    ]);
    $booking->update(['assigned_unit_id' => $unit->id, 'assigned_team_leader_id' => $teamLeader->id, 'assigned_at' => now()]);

    // The customer pays online and it is verified...
    pmsWebhook($this, pmsPaidEvent('evt_tl', 'cs_test_session_1', 'pay_tl', 235235))->assertJsonPath('outcome', 'paid');
    expect($booking->fresh()->status)->toBe('arrived_dropoff');

    // ...but the Team Leader still has to complete the task the usual way: signature and proof are not waived.
    Sanctum::actingAs($teamLeader, ['*']);
    $completeUrl = "/api/v1/team-leader/task/{$booking->booking_code}/complete";

    $this->post($completeUrl, ['payment_method' => 'gcash'])->assertStatus(422); // no signature
    $this->post($completeUrl, ['payment_method' => 'gcash', 'signature' => UploadedFile::fake()->image('sig.jpg')])->assertStatus(422); // no proof
    expect($booking->fresh()->status)->toBe('arrived_dropoff');

    $booking->update(['payment_proof_path' => 'task-photos/fake-proof.jpg']);
    $this->post($completeUrl, ['payment_method' => 'gcash', 'signature' => UploadedFile::fake()->image('sig.jpg')])->assertOk();

    $booking->refresh();
    expect($booking->status)->toBe('waiting_verification');
    expect($booking->payment_method)->toBe('gcash');            // collected method, written by the Team Leader flow
    expect($booking->preferred_payment_method)->toBe('gcash');  // customer's earlier choice, independent
    expect(PaymentTransaction::dispatcherNote($booking))->toContain('Paid online (sandbox)');

    // The dispatcher is still the only one who completes the job.
    $this->actingAs($dispatcher)->post(route('admin.jobs.confirm-payment', $booking))->assertOk();
    expect($booking->fresh()->status)->toBe('completed');
    expect($transaction->fresh()->status)->toBe('paid');
    expect(PaymentTransaction::where('status', 'paid')->count())->toBe(1);
});

it('adds no queries and shows nothing to the dispatcher while the feature is off', function () {
    $user = pmsCustomerUser();
    $booking = pmsBookingFor($user);

    expect(config('paymongo.enabled'))->toBeFalse();

    DB::enableQueryLog();
    expect(PaymentTransaction::dispatcherNote($booking))->toBeNull();
    expect(DB::getQueryLog())->toBe([]);
});

// ───────────────────────── safety guard (replaces the old blanket PayMongo ban) ─────────────────────────

it('exposes exactly the approved PayMongo routes, all protected as intended', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains(strtolower($route->uri()), 'paymongo') || str_contains($route->uri(), 'online-payment'));

    expect($routes->map(fn ($r) => $r->methods()[0] . ' ' . $r->uri())->sort()->values()->all())->toBe([
        'GET api/v1/bookings/{code}/online-payment',
        'POST api/v1/bookings/{code}/online-payment/cancel',
        'POST api/v1/bookings/{code}/online-payment/checkout',
        'POST api/webhooks/paymongo',
    ]);

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();

        if (str_contains($route->uri(), 'webhooks')) {
            // Unauthenticated by design; protected by the raw-body signature and a throttle.
            expect($middleware)->toContain('throttle:paymongo-webhook')->not->toContain('auth:sanctum');
        } else {
            expect($middleware)->toContain('auth:sanctum')->toContain('account.active');
        }
    }
});

it('never exposes gateway secrets through config defaults, responses or the transaction model', function () {
    expect(config('services.paymongo'))->toBeNull();
    expect(config('paymongo.enabled'))->toBeFalse();
    expect(config('paymongo.secret_key'))->toBeNull();
    expect(config('paymongo.webhook_secret'))->toBeNull();
    expect(config('paymongo.channels'))->toBe(['gcash' => 'gcash']);
    expect(config('paymongo.allowed_environments'))->not->toContain('production');

    [$transaction] = pmsOpenSession($this);
    expect(array_keys($transaction->toArray()))->not->toContain('checkout_url');
    expect(json_encode($transaction->toArray()))->not->toContain(PMS_SECRET_KEY)->not->toContain(PMS_WEBHOOK_SECRET);
});

it('keeps secrets out of the Flutter and web client sources', function () {
    $roots = [base_path('towmate_app/lib'), base_path('public/js'), base_path('public/dispatcher/js'), base_path('public/superadmin/js'), base_path('resources/views')];
    $hits = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! in_array($file->getExtension(), ['dart', 'js', 'php'], true)) {
                continue;
            }
            if (preg_match('/sk_(test|live)_[A-Za-z0-9]{8,}|PAYMONGO_SECRET_KEY|PAYMONGO_WEBHOOK_SECRET/', file_get_contents($file->getPathname()))) {
                $hits[] = $file->getPathname();
            }
        }
    }

    expect($hits)->toBe([]);
});

it('keeps the historical removal migration and leaves no PayMongo columns on bookings', function () {
    expect(file_exists(database_path('migrations/2026_09_29_000000_drop_paymongo_fields_from_bookings_table.php')))->toBeTrue();

    foreach (['paymongo_link_id', 'paymongo_checkout_url', 'paymongo_intent_id', 'paymongo_client_key'] as $column) {
        expect(Schema::hasColumn('bookings', $column))->toBeFalse();
    }
});

// ───────────────────────── operator-only provider diagnostics ─────────────────────────

it('shows the operator the provider\'s error text with credentials redacted, and never puts it in an HTTP response or the database', function () {
    [$user, $booking] = pmsReadyForPilot();
    pmsFakeGateway(['create' => Http::response(['errors' => [[
        'code' => 'parameter_invalid',
        'detail' => 'GCash is not enabled. Key sk_test_abcdef1234567890 and secret whsk_ABCDEF1234567890 must not be echoed.',
    ]]], 400)]);

    $exit = Artisan::call('online-payments:pilot-checkout', ['booking_code' => $booking->booking_code, '--user' => $user->id, '--confirm-sandbox' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    expect($output)->toContain('error code=parameter_invalid detail=GCash is not enabled. Key [redacted] and secret [redacted]');
    expect($output)->not->toContain('abcdef1234567890')->not->toContain('ABCDEF1234567890');

    // The customer path gets only the generic message, and the DB keeps only the coarse code.
    Sanctum::actingAs($user, ['*']);
    pmsFakeGateway(['create' => Http::response(['errors' => [['code' => 'parameter_invalid', 'detail' => 'GCash is not enabled.']]], 400)]);
    $response = $this->postJson("/api/v1/bookings/{$booking->booking_code}/online-payment/checkout", ['method' => 'gcash'])->assertStatus(502);

    expect($response->getContent())->not->toContain('GCash is not enabled')->not->toContain('diagnostic');
    expect(PaymentTransaction::pluck('failure_code')->unique()->all())->toBe(['provider_rejected:parameter_invalid']);
});

it('describes an unexpected create response by its structure only, never its values', function () {
    [$user, $booking] = pmsReadyForPilot();
    pmsFakeGateway(['create' => Http::response(['data' => [
        'id' => 'cs_abc123',
        'type' => 'checkout_session',
        'attributes' => [
            'checkout_url' => 'https://evil.example/pay?token=VALUE_MUST_NOT_APPEAR',
            'livemode' => false,
            'note' => 'VALUE_MUST_NOT_APPEAR',
            'payments' => [],
        ],
    ]], 200)]);

    $exit = Artisan::call('online-payments:pilot-checkout', ['booking_code' => $booking->booking_code, '--user' => $user->id, '--confirm-sandbox' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    foreach (['data.attributes.checkout_url: url host=evil.example', 'data.attributes.livemode: bool=false', 'data.attributes.note: string(len 21)', 'data.attributes.payments: list(0)', 'data.id: string(len 9, prefix cs_)', 'data.type: string=checkout_session'] as $expected) {
        expect($output)->toContain($expected);
    }
    expect($output)->not->toContain('VALUE_MUST_NOT_APPEAR')->not->toContain('token=');

    expect(PaymentTransaction::firstOrFail()->failure_code)->toBe('provider_unexpected_response');
});

it('describes lists by their first element without doubled index paths', function () {
    [$user, $booking] = pmsReadyForPilot();
    pmsFakeGateway(['create' => Http::response(['errors' => [['code' => 'parameter_invalid', 'detail' => 'x']]], 400)]);

    Artisan::call('online-payments:pilot-checkout', ['booking_code' => $booking->booking_code, '--user' => $user->id, '--confirm-sandbox' => true]);
    $output = Artisan::output();

    expect($output)->toContain('errors: list(1)')->toContain('errors[0].code: string(len 17)')->not->toContain('errors[0].0');
});

// ───────────────────────── customer app: "Pay Now" offer on the booking detail ─────────────────────────

function pmsDetail($test, User $user, Booking $booking): array
{
    Sanctum::actingAs($user, ['*']);

    return $test->getJson("/api/v1/bookings/{$booking->booking_code}/detail")->assertOk()->json('data');
}

it('offers Pay Now with the server-calculated final amount and the chosen method', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    $booking = pmsBookingFor($user);

    $offer = pmsDetail($this, $user, $booking)['online_payment'];

    expect($offer)->toMatchArray([
        'available' => true,
        'eligible' => true,
        'reason' => null,
        'amount' => '2352.35',
        'currency' => 'PHP',
        'method' => 'gcash',
        'method_label' => 'GCash',
        'is_group' => false,
        'transaction' => null,
    ]);
});

it('says nothing about online payment to anyone outside the sandbox allow-list, or while it is disabled', function () {
    $tester = pmsCustomerUser();
    $ordinary = pmsCustomerUser();
    $booking = pmsBookingFor($ordinary);

    // Feature off (the default): no block at all, and the detail endpoint still works as before.
    expect(pmsDetail($this, $ordinary, $booking))->toHaveKey('online_payment')->and(pmsDetail($this, $ordinary, $booking)['online_payment'])->toBeNull();

    pmsEnable($tester); // on, but this customer is not allow-listed
    expect(pmsDetail($this, $ordinary, $booking)['online_payment'])->toBeNull();

    pmsEnable($tester, $ordinary);
    config(['paymongo.secret_key' => '']); // allow-listed but not configured: still nothing to show
    expect(pmsDetail($this, $ordinary, $booking)['online_payment'])->toBeNull();
});

it('explains with a stable reason why Pay Now is not offered', function (array $booking, string $reason) {
    $user = pmsCustomerUser();
    pmsEnable($user);

    $offer = pmsDetail($this, $user, pmsBookingFor($user, $booking))['online_payment'];

    expect($offer['eligible'])->toBeFalse();
    expect($offer['reason'])->toBe($reason);
})->with([
    'cash preference' => [['preferred_payment_method' => 'cash'], 'preference_not_online'],
    'no preference (older booking)' => [['preferred_payment_method' => null], 'preference_not_online'],
    'online banking (not wired yet)' => [['preferred_payment_method' => 'bank_transfer'], 'channel_unavailable'],
    'tow not yet at the drop-off' => [['status' => 'on_the_way'], 'not_ready'],
    'cash already recorded' => [['payment_method' => 'cash', 'cash_received' => 2400], 'payment_already_recorded'],
    'payment already submitted by the team leader' => [['payment_submitted_at' => '2026-11-01 10:00:00'], 'payment_already_recorded'],
]);

it('reflects the verified transaction in the offer, and never exposes links, provider ids or secrets', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    pmsFakeGateway();
    $booking = pmsBookingFor($user);
    Sanctum::actingAs($user, ['*']);
    $this->postJson("/api/v1/bookings/{$booking->booking_code}/online-payment/checkout", ['method' => 'gcash'])->assertCreated();

    // An open session: Pay Now resumes it (still eligible), and the detail carries no link.
    $offer = pmsDetail($this, $user, $booking)['online_payment'];
    expect($offer['eligible'])->toBeTrue();
    expect($offer['transaction']['status'])->toBe('pending');
    expect(json_encode($offer))->not->toContain('checkout_url')->not->toContain('cs_test_session_1')->not->toContain(PMS_SECRET_KEY)->not->toContain('paymongo.test');

    // Verified paid: no longer offered, and the booking itself is untouched.
    pmsWebhook($this, pmsPaidEvent('evt_offer', 'cs_test_session_1', 'pay_offer', 235235))->assertJsonPath('outcome', 'paid');
    $detail = pmsDetail($this, $user, $booking);
    expect($detail['online_payment'])->toMatchArray(['eligible' => false, 'reason' => 'already_paid']);
    expect($detail['online_payment']['transaction']['status'])->toBe('paid');
    expect($detail['status'])->toBe('arrived_dropoff');
    expect($detail['payment_method'])->toBeNull(); // collected method is still the Team Leader's to record
});

it('marks a payment awaiting review as under review rather than payable again', function () {
    [$transaction, $booking, $user] = pmsOpenSession($this);
    $transaction->update(['status' => 'review', 'flag' => 'amount_mismatch']);

    $offer = pmsDetail($this, $user, $booking)['online_payment'];

    expect($offer['eligible'])->toBeFalse();
    expect($offer['reason'])->toBe('under_review');
    expect($offer['transaction']['status'])->toBe('review');
});

it('offers one group amount for a multi-vehicle booking', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    [$first, $second] = pmsGroupFor($user);

    $offer = pmsDetail($this, $user, $second)['online_payment'];

    expect($offer['eligible'])->toBeTrue();
    expect($offer['is_group'])->toBeTrue();
    expect($offer['amount'])->toBe('3402.35');
});

it('reports gateway success and booking completion as two separate facts on the status endpoint', function () {
    [$transaction, $booking, $user] = pmsOpenSession($this);
    pmsWebhook($this, pmsPaidEvent('evt_two', 'cs_test_session_1', 'pay_two', 235235))->assertJsonPath('outcome', 'paid');
    Sanctum::actingAs($user, ['*']);

    $this->getJson("/api/v1/bookings/{$booking->booking_code}/online-payment")->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.booking_status', 'arrived_dropoff')
        ->assertJsonPath('data.booking_completed', false);

    $booking->update(['status' => 'completed']); // only the dispatcher's confirmation does this

    $this->getJson("/api/v1/bookings/{$booking->booking_code}/online-payment")->assertOk()
        ->assertJsonPath('data.booking_completed', true);
});

// ── Production refusal: the sandbox can never run in production, whatever the environment variables say ──

function pmsAsEnvironment($test, string $environment, callable $body): void
{
    $previous = app()['env'];
    app()['env'] = $environment;

    try {
        $body();
    } finally {
        app()['env'] = $previous;
    }
}

it('refuses production even when production is listed in the allowed environments', function (string $environment) {
    $user = pmsCustomerUser();
    pmsEnable($user);
    config(['paymongo.allowed_environments' => ['local', 'testing', 'staging', 'production', 'prod', 'Production', $environment]]);

    pmsAsEnvironment($this, $environment, function () use ($user) {
        $guard = new \App\Services\OnlinePayments\SandboxGuard();

        expect($guard->environmentAllowed())->toBeFalse();
        expect($guard->enabled())->toBeFalse();
        expect(fn () => $guard->secretKey())->toThrow(\App\Services\OnlinePayments\GatewayException::class);
        expect(app(\App\Services\OnlinePayments\OnlinePaymentService::class)->availableFor($user))->toBeFalse();
    });
})->with(['production', 'Production', 'PRODUCTION', 'prod', ' production ']);

it('lets no request reach PayMongo from production: checkout, status, cancel and webhook all 404', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    config(['paymongo.allowed_environments' => ['production']]);
    pmsFakeGateway();
    $booking = pmsBookingFor($user);

    pmsAsEnvironment($this, 'production', function () use ($user, $booking) {
        pmsCheckout($this, $user, $booking)->assertNotFound();
        Sanctum::actingAs($user, ['*']); $this->getJson("/api/v1/bookings/{$booking->booking_code}/online-payment")->assertNotFound();
        $this->postJson("/api/v1/bookings/{$booking->booking_code}/online-payment/cancel")->assertNotFound();
        pmsWebhook($this, pmsPaidEvent('evt_prod_1', 'cs_test_session_1', 'pay_prod_1', 100000))->assertNotFound();

        expect(pmsDetail($this, $user, $booking)['online_payment'])->toBeNull();
    });

    Http::assertNothingSent();
    expect(\App\Models\PaymentTransaction::count())->toBe(0);
    expect(\App\Models\PaymentWebhookEvent::count())->toBe(0);
});

it('still runs in the demo (staging) environment when explicitly allowed', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);
    config(['paymongo.allowed_environments' => ['staging']]);

    pmsAsEnvironment($this, 'staging', function () use ($user) {
        expect((new \App\Services\OnlinePayments\SandboxGuard())->enabled())->toBeTrue();
        expect(app(\App\Services\OnlinePayments\OnlinePaymentService::class)->availableFor($user))->toBeTrue();
    });
});

it('the readiness check reports production as a blocker', function () {
    pmsEnable();
    config(['paymongo.allowed_environments' => ['production']]);

    pmsAsEnvironment($this, 'production', fn () => $this->artisan('online-payments:check')->assertFailed());
});

// ── Capstone single-production setup: explicit production opt-in, demo bookings, accounting isolation ──

/** Production with the capstone opt-in, TEST credentials, and the given allow-listed demo accounts. */
function pmsEnableProduction(User ...$allowed): void
{
    pmsEnable(...$allowed);
    config(['paymongo.allowed_environments' => ['local', 'testing', 'staging'], 'paymongo.allow_production' => true]);
}

it('keeps the production opt-in off by default', function () {
    expect(config('paymongo.allow_production'))->toBeFalse();
});

it('runs in production only with the explicit opt-in, for an allow-listed demo account', function () {
    $user = pmsCustomerUser();
    pmsEnableProduction($user);

    pmsAsEnvironment($this, 'production', function () use ($user) {
        expect((new \App\Services\OnlinePayments\SandboxGuard())->enabled())->toBeTrue();
        expect(app(\App\Services\OnlinePayments\OnlinePaymentService::class)->availableFor($user))->toBeTrue();

        config(['paymongo.allow_production' => false]);
        expect((new \App\Services\OnlinePayments\SandboxGuard())->enabled())->toBeFalse();
    });
});

it('always rejects a live key in production, even with the opt-in', function () {
    $user = pmsCustomerUser();
    pmsEnableProduction($user);
    config(['paymongo.secret_key' => 'sk_live_must_never_be_used_123']);
    pmsFakeGateway();
    $booking = pmsBookingFor($user);

    pmsAsEnvironment($this, 'production', function () use ($user, $booking) {
        $guard = new \App\Services\OnlinePayments\SandboxGuard();
        expect($guard->credentialsProblem())->toBe('live_or_unknown_key_rejected');
        expect(fn () => $guard->secretKey())->toThrow(\App\Services\OnlinePayments\GatewayException::class);
        expect(pmsDetail($this, $user, $booking)['online_payment'])->toBeNull();
        expect(pmsCheckout($this, $user, $booking)->status())->not->toBeIn([200, 201]);
    });

    Http::assertNothingSent();
    expect(\App\Models\PaymentTransaction::count())->toBe(0);
});

it('requires the test webhook secret in production', function () {
    $user = pmsCustomerUser();
    pmsEnableProduction($user);
    config(['paymongo.webhook_secret' => '']);
    $booking = pmsBookingFor($user);

    pmsAsEnvironment($this, 'production', fn () => expect(pmsDetail($this, $user, $booking)['online_payment'])->toBeNull());
});

it('never lets a normal customer start a sandbox payment in production', function () {
    $demo = pmsCustomerUser();
    $normal = pmsCustomerUser();
    pmsEnableProduction($demo);
    pmsFakeGateway();
    $booking = pmsBookingFor($normal);

    pmsAsEnvironment($this, 'production', function () use ($normal, $booking) {
        expect(pmsDetail($this, $normal, $booking)['online_payment'])->toBeNull();
        pmsCheckout($this, $normal, $booking)->assertNotFound();
    });

    Http::assertNothingSent();
    expect($booking->fresh()->is_demo)->toBeFalse();
});

it('completes a GCash test payment in production through the signed webhook, leaving the booking for the dispatcher', function () {
    $user = pmsCustomerUser();
    pmsEnableProduction($user);
    pmsFakeGateway();
    $booking = pmsBookingFor($user);

    pmsAsEnvironment($this, 'production', function () use ($user, $booking) {
        pmsCheckout($this, $user, $booking)->assertCreated();
        $transaction = \App\Models\PaymentTransaction::firstOrFail();

        pmsWebhook($this, pmsPaidEvent('evt_prod_ok', 'cs_test_session_1', 'pay_prod_ok', 235235), 't=1,te=forged,li=')->assertUnauthorized();
        expect($transaction->fresh()->status)->toBe('pending');

        pmsWebhook($this, pmsPaidEvent('evt_prod_ok', 'cs_test_session_1', 'pay_prod_ok', 235235))->assertOk();
        pmsWebhook($this, pmsPaidEvent('evt_prod_ok', 'cs_test_session_1', 'pay_prod_ok', 235235))->assertOk(); // replay

        expect($transaction->fresh()->status)->toBe('paid');
        expect($transaction->fresh()->livemode)->toBeFalse();
        expect(\App\Models\PaymentWebhookEvent::count())->toBe(1);
        expect($booking->fresh()->status)->toBe('arrived_dropoff');
        expect($booking->fresh()->payment_method)->toBeNull();
    });
});

it('marks bookings by allow-listed demo accounts as demo, once, and never normal ones', function () {
    $demo = pmsCustomerUser();
    $normal = pmsCustomerUser();
    pmsEnable($demo);

    $demoBooking = pmsBookingFor($demo);
    $normalBooking = pmsBookingFor($normal, ['is_demo' => true]); // not mass-assignable: ignored

    expect($demoBooking->fresh()->is_demo)->toBeTrue();
    expect($normalBooking->fresh()->is_demo)->toBeFalse();

    // The designation is persistent: emptying the allow-list later does not turn a demo booking into revenue.
    config(['paymongo.test_user_ids' => [], 'paymongo.test_user_emails' => []]);
    $demoBooking->update(['status' => 'completed']);
    expect($demoBooking->fresh()->is_demo)->toBeTrue();
});

it('marks every vehicle of a demo group booking as demo', function () {
    $user = pmsCustomerUser();
    pmsEnable($user);

    [$first, $second] = pmsGroupFor($user);

    expect($first->is_demo)->toBeTrue();
    expect($second->is_demo)->toBeTrue();
});

it('refuses a sandbox payment on a booking that is not a demo booking, even for an allow-listed account', function () {
    $user = pmsCustomerUser();
    $booking = pmsBookingFor($user); // made before the account was allow-listed: real business
    pmsEnable($user);
    pmsFakeGateway();

    expect($booking->fresh()->is_demo)->toBeFalse();
    expect(pmsDetail($this, $user, $booking)['online_payment']['reason'])->toBe('not_demo_booking');
    pmsCheckout($this, $user, $booking)->assertStatus(409)->assertJsonPath('reason', 'not_demo_booking');
    Http::assertNothingSent();
});

it('keeps demo bookings out of every Owner revenue and volume figure', function () {
    $demo = pmsCustomerUser();
    $normal = pmsCustomerUser();
    pmsEnable($demo);
    $completed = ['status' => 'completed', 'completed_at' => now(), 'final_total' => 1000.00];

    pmsBookingFor($demo, ['final_total' => 99999.00] + $completed);
    pmsBookingFor($demo, ['status' => 'cancelled']);
    pmsBookingFor($normal, $completed);

    $metrics = app(\App\Services\ReportMetricsService::class);
    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    expect($metrics->revenueSummary($start, $end)['totalRevenue'])->toEqual(1000.0);
    expect($metrics->averageRevenuePerJob($start, $end)['completed_jobs'])->toBe(1);
    expect(round(array_sum(array_column($metrics->dailyRevenue(now()->subDays(6), 7), 'revenue')), 2))->toEqual(1000.0);
    expect($metrics->cancellationRate($start, $end)['cancelled'])->toBe(0);
    expect((float) Booking::query()->excludingDemo()->where('status', 'completed')->sum('final_total'))->toEqual(1000.0);
});

it('keeps demo bookings out of the Owner reports export', function () {
    $demo = pmsCustomerUser();
    pmsEnable($demo);
    pmsBookingFor($demo, ['status' => 'completed', 'completed_at' => now(), 'final_total' => 55555.00]);

    $owner = User::factory()->create(['role_id' => pmsRole(1, 'Super Admin')->id, 'status' => 'active', 'must_change_password' => false]);
    $csv = $this->actingAs($owner)->get(route('superadmin.reports.export', ['period' => 'month']));

    $csv->assertOk();
    expect($csv->streamedContent())
        ->not->toContain('55,555.00')
        ->toContain('"Total Bookings (created in period)",0')
        ->toContain('"Completed revenue (by completion date)",0.00');
});

it('labels a demo invoice and receipt as TEST / DEMO and leaves real ones untouched', function () {
    $demo = pmsCustomerUser();
    $normal = pmsCustomerUser();
    pmsEnable($demo);
    $presenter = app(\App\Services\DocumentPresenter::class);

    foreach ([[$demo, true], [$normal, false]] as [$user, $isDemo]) {
        $booking = pmsBookingFor($user, ['status' => 'completed', 'completed_at' => now()]);
        $invoice = \App\Models\Invoice::create(['booking_id' => $booking->id, 'subtotal' => 2100, 'additional_fee' => 0, 'discount' => 0, 'total' => 2352.35, 'status' => 'issued', 'is_current' => true]);
        $receipt = \App\Models\Receipt::create(['booking_id' => $booking->id, 'invoice_id' => $invoice->id, 'generated_by' => $user->id, 'receipt_number' => 'R-PMS-' . uniqid(), 'pdf_path' => 'x.pdf', 'email_sent' => false]);

        $invoiceHtml = view('documents.invoice', ['doc' => $presenter->invoice($invoice)])->render();
        $receiptHtml = view('documents.receipt', ['doc' => $presenter->receipt($receipt)])->render();
        $subject = (new \App\Mail\FinalPaymentDocumentsMail($booking, $invoice, $receipt))->envelope()->subject;

        if ($isDemo) {
            expect($invoiceHtml)->toContain('TEST / DEMO — NO REAL MONEY')->toContain('Not a valid invoice');
            expect($receiptHtml)->toContain('TEST / DEMO — NO REAL MONEY')->toContain('Not a valid receipt');
            expect($subject)->toStartWith('[TEST / DEMO — NO REAL MONEY]');
        } else {
            expect($invoiceHtml)->not->toContain('TEST / DEMO');
            expect($receiptHtml)->not->toContain('TEST / DEMO');
            expect($subject)->not->toContain('TEST');
        }
    }
});

it('lets the dispatcher confirm a demo booking as usual, which still never counts as revenue', function () {
    \Illuminate\Support\Facades\Mail::fake();
    $demo = pmsCustomerUser();
    pmsEnable($demo);
    $booking = pmsBookingFor($demo, ['status' => 'waiting_verification', 'payment_method' => 'cash', 'cash_received' => 2352.35, 'payment_submitted_at' => now()]);
    $dispatcher = User::factory()->create(['role_id' => pmsRole(2, 'Dispatcher')->id, 'status' => 'active', 'must_change_password' => false]);

    $this->actingAs($dispatcher)->post(route('admin.jobs.confirm-payment', $booking))->assertOk();

    expect($booking->fresh()->status)->toBe('completed');
    expect($booking->fresh()->is_demo)->toBeTrue();
    expect(app(\App\Services\ReportMetricsService::class)->revenueSummary(now()->startOfMonth(), now()->endOfMonth())['totalRevenue'])->toEqual(0.0);
});
