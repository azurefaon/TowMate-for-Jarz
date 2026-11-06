<?php

/*
 * The simplified payment workflow:
 *  - Customer Step 3: Cash → SUBMIT BOOKING (submitted at once); GCash → PAY & SUBMIT (PayMongo TEST checkout; the
 *    booking is submitted only after a verified payment, exactly once).
 *  - Team Leader at the drop-off: a verified prepaid booking → COMPLETE TASK (no collection, no dispatcher
 *    verification); otherwise CASH ONLY → amount + photo + signature → dispatcher verification → completed.
 *  - Pushes: "Booking submitted" to the customer only once the booking really exists; "New Towing Assignment" to the
 *    one Team Leader a task was assigned to.
 */

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\DispatcherNotification;
use App\Models\PaymentTransaction;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

const PBF_SECRET_KEY = 'sk_test_pbf_fake_key_0000000000000000';
const PBF_WEBHOOK_SECRET = 'whsk_pbf_fake_webhook_secret_000000';

function pbfRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function pbfCustomer(): User
{
    $user = User::factory()->create(['role_id' => pbfRole(5, 'Customer')->id, 'status' => 'active']);
    Customer::create(['user_id' => $user->id, 'full_name' => $user->name, 'phone' => '0917' . fake()->unique()->numerify('#######'), 'email' => $user->email]);

    return $user->fresh();
}

function pbfTeamLeader(): User
{
    return User::factory()->create(['role_id' => pbfRole(3, 'Team Leader')->id, 'status' => 'active', 'must_change_password' => false]);
}

/** Sandbox on (TEST keys only) for these allow-listed demo users. */
function pbfEnable(User ...$allowed): void
{
    config([
        'paymongo.enabled' => true,
        'paymongo.secret_key' => PBF_SECRET_KEY,
        'paymongo.webhook_secret' => PBF_WEBHOOK_SECRET,
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

/** Throwaway FCM service account (never a real credential), as in BookingStatusPushTest. */
function pbfConfigureFcm(): void
{
    $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $key = @openssl_pkey_new($options);
    foreach (['C:/xampp/php/extras/ssl/openssl.cnf', '/etc/ssl/openssl.cnf'] as $cnf) {
        if ($key !== false) {
            break;
        }
        if (is_file($cnf)) {
            $options['config'] = $cnf;
            $key = @openssl_pkey_new($options);
        }
    }
    openssl_pkey_export($key, $pem, null, isset($options['config']) ? ['config' => $options['config']] : []);

    config([
        'services.fcm.service_account_base64' => base64_encode(json_encode(['client_email' => 'push@towmate-test.iam.gserviceaccount.com', 'private_key' => $pem, 'project_id' => 'towmate-test'])),
        'services.fcm.project_id' => 'towmate-test',
    ]);
    Cache::flush();
}

/**
 * One fake for every outside service: PayMongo (create / retrieve / expire) and FCM (token + send).
 * `retrieve_paid` makes the provider report the session as paid (a webhook that has not arrived yet).
 */
function pbfFakeOutside(array $over = []): void
{
    Http::swap(new \Illuminate\Http\Client\Factory());
    $created = 0;

    Http::fake(function (HttpRequest $request) use ($over, &$created) {
        $url = $request->url();

        if (str_contains($url, 'oauth2.googleapis.com')) {
            return Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3600], 200);
        }
        if (str_contains($url, 'fcm.googleapis.com')) {
            $token = $request['message']['token'] ?? '';

            return str_starts_with($token, 'stale-')
                ? Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404)
                : Http::response(['name' => 'projects/towmate-test/messages/1'], 200);
        }
        if (str_ends_with($url, '/v2/checkout_sessions')) {
            $id = 'cs_test_pbf_' . (++$created);

            return Http::response(['data' => ['id' => $id, 'type' => 'checkout_session', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.test/' . $id, 'livemode' => false]]], 200);
        }
        if (str_ends_with($url, '/expire')) {
            return $over['expire'] ?? Http::response(['data' => ['attributes' => ['status' => 'expired']]], 200);
        }

        // Retrieve: either still open, or paid (with the reference the transaction carries).
        $sessionId = basename(parse_url($url, PHP_URL_PATH));
        $transaction = PaymentTransaction::where('provider_checkout_session_id', $sessionId)->first();
        $payments = ($over['retrieve_paid'] ?? false) && $transaction
            ? [['id' => 'pay_pbf_' . $transaction->id, 'type' => 'payment', 'attributes' => ['status' => 'paid', 'amount' => $transaction->amount_centavos, 'currency' => 'PHP', 'livemode' => false]]]
            : [];

        return Http::response(['data' => ['id' => $sessionId, 'attributes' => ['status' => $payments ? 'paid' : 'active', 'livemode' => false, 'reference_number' => $transaction?->reference, 'payments' => $payments]]], 200);
    });
}

/** @return array<int, array{title: ?string, body: ?string, token: ?string, data: array}> FCM messages sent so far */
function pbfPushes(): array
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'fcm.googleapis.com'))
        ->map(fn ($pair) => [
            'title' => $pair[0]['message']['notification']['title'] ?? null,
            'body' => $pair[0]['message']['notification']['body'] ?? null,
            'token' => $pair[0]['message']['token'] ?? null,
            'data' => $pair[0]['message']['data'] ?? [],
        ])->values()->all();
}

/** A Book Now request the customer can submit (vehicle type with a ready unit). */
function pbfRequestPayload(): array
{
    $truck = TruckType::firstOrCreate(['name' => 'PBF Light Duty'], ['base_rate' => 1500, 'per_km_rate' => 60, 'status' => 'active']);
    $vehicle = VehicleType::firstOrCreate(['name' => 'PBF Sedan'], ['category' => '4_wheeler', 'required_truck_type_id' => $truck->id, 'status' => 'active']);
    if (! Unit::where('truck_type_id', $truck->id)->exists()) {
        $leader = pbfTeamLeader();
        Cache::put("teamleader:presence:{$leader->id}", now()->timestamp, now()->addMinutes(30));
        Unit::create(['name' => 'PBF Unit', 'plate_number' => fake()->unique()->bothify('???-####'), 'truck_type_id' => $truck->id, 'team_leader_id' => $leader->id, 'status' => 'available', 'driver_name' => 'PBF Driver']);
    }

    return [
        'vehicle_type_id' => $vehicle->id,
        'pickup_address' => 'Origin ' . uniqid(),
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'dropoff_address' => 'Destination ' . uniqid(),
        'dropoff_lat' => 14.6905,
        'dropoff_lng' => 120.9842,
        'distance_km' => 10,
        'service_type' => 'book_now',
        'vehicle_images' => [UploadedFile::fake()->image('pbf-vehicle.jpg', 300, 300)],
    ];
}

function pbfSubmit($test, User $user, array $overrides = [])
{
    Sanctum::actingAs($user, ['*']);

    return $test->post('/api/v1/bookings', array_merge(pbfRequestPayload(), $overrides), ['Accept' => 'application/json']);
}

function pbfPayAndSubmit($test, User $user)
{
    return pbfSubmit($test, $user, ['preferred_payment_method' => 'gcash', 'pay_online' => 1]);
}

function pbfPaidWebhook($test, PaymentTransaction $transaction, string $eventId = 'evt_pbf_paid', string $paymentId = 'pay_pbf_paid')
{
    $transaction->refresh();
    $raw = json_encode(['data' => ['id' => $eventId, 'type' => 'event', 'attributes' => [
        'type' => 'checkout_session.payment.paid',
        'livemode' => false,
        'data' => ['id' => $transaction->provider_checkout_session_id, 'type' => 'checkout_session', 'attributes' => [
            'livemode' => false,
            'reference_number' => $transaction->reference,
            'payments' => [['id' => $paymentId, 'type' => 'payment', 'attributes' => ['status' => 'paid', 'amount' => $transaction->amount_centavos, 'currency' => 'PHP', 'livemode' => false]]],
        ]],
    ]]]);
    $t = (string) time();

    return $test->call('POST', '/api/webhooks/paymongo', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PAYMONGO_SIGNATURE' => "t={$t},te=" . hash_hmac('sha256', $t . '.' . $raw, PBF_WEBHOOK_SECRET) . ',li=',
    ], $raw);
}

/** Puts a booking at the drop-off, assigned to a Team Leader with a unit on the job. */
function pbfAtDropoff(Booking $booking, ?User $teamLeader = null): array
{
    $teamLeader ??= pbfTeamLeader();
    $unit = Unit::create(['name' => 'PBF Job Unit ' . uniqid(), 'plate_number' => fake()->unique()->bothify('???-####'), 'truck_type_id' => $booking->truck_type_id, 'team_leader_id' => $teamLeader->id, 'status' => 'on_job']);
    $booking->update(['status' => 'arrived_dropoff', 'assigned_unit_id' => $unit->id, 'assigned_team_leader_id' => $teamLeader->id, 'assigned_at' => now()]);

    return [$booking->fresh(), $teamLeader, $unit];
}

beforeEach(function () {
    Mail::fake();
    pbfFakeOutside();
});

// ───────────────────────── Customer: Cash ─────────────────────────

it('submits a Cash booking immediately, with no PayMongo call, and pushes "Booking submitted" once', function () {
    pbfConfigureFcm();
    $user = pbfCustomer();
    DeviceToken::create(['user_id' => $user->id, 'token' => 'customer-device-token-0000000001', 'platform' => 'android']);

    $response = pbfSubmit($this, $user, ['preferred_payment_method' => 'cash'])->assertCreated()->assertJsonPath('success', true);

    $booking = Booking::where('booking_code', $response->json('booking_code'))->firstOrFail();
    expect($booking->status)->toBe('requested');
    expect($booking->preferred_payment_method)->toBe('cash');
    expect(PaymentTransaction::count())->toBe(0);
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'paymongo'));
    expect(collect(pbfPushes())->pluck('title')->all())->toBe(['Booking submitted']);
    expect(pbfPushes()[0]['data']['booking_code'])->toBe($booking->booking_code);
});

// ───────────────────────── Customer: Pay & Submit ─────────────────────────

it('keeps a Pay & Submit booking hidden and unannounced until the payment is verified', function () {
    pbfConfigureFcm();
    $user = pbfCustomer();
    pbfEnable($user);
    DeviceToken::create(['user_id' => $user->id, 'token' => 'customer-device-token-0000000002', 'platform' => 'android']);

    $response = pbfPayAndSubmit($this, $user)->assertCreated()
        ->assertJsonPath('payment_required', true)
        ->assertJsonPath('booking_submitted', false)
        ->assertJsonPath('payment.status', 'pending')
        ->assertJsonPath('test_mode', true);
    $code = $response->json('booking_code');
    expect($response->json('payment.checkout_url'))->toStartWith('https://checkout.paymongo.test/');

    // Invisible to dispatch, Team Leaders, reports and the customer's own lists; nothing announced, no quotation.
    expect(Booking::where('booking_code', $code)->exists())->toBeFalse();
    expect(Booking::includingUnsubmitted()->where('booking_code', $code)->value('status'))->toBe(Booking::STATUS_AWAITING_PAYMENT);
    expect(DispatcherNotification::count())->toBe(0);
    expect(Quotation::count())->toBe(0);
    expect(pbfPushes())->toBe([]);
    $this->getJson("/api/v1/bookings/{$code}/detail")->assertNotFound();
});

it('submits the paid booking exactly once on the verified webhook, even when the webhook is replayed', function () {
    pbfConfigureFcm();
    $user = pbfCustomer();
    pbfEnable($user);
    DeviceToken::create(['user_id' => $user->id, 'token' => 'customer-device-token-0000000003', 'platform' => 'android']);
    $code = pbfPayAndSubmit($this, $user)->assertCreated()->json('booking_code');
    $transaction = PaymentTransaction::firstOrFail();

    pbfPaidWebhook($this, $transaction)->assertOk()->assertJsonPath('outcome', 'paid');
    pbfPaidWebhook($this, $transaction)->assertOk()->assertJsonPath('outcome', 'replay');
    pbfPaidWebhook($this, $transaction, 'evt_pbf_paid_again')->assertOk()->assertJsonPath('outcome', 'already_paid');

    $booking = Booking::where('booking_code', $code)->firstOrFail();
    expect($booking->status)->toBe('confirmed');
    expect($booking->is_demo)->toBeTrue();
    expect(Quotation::where('status', 'accepted')->count())->toBe(1);
    expect((int) round((float) Quotation::firstOrFail()->estimated_price * 100))->toBe($transaction->amount_centavos);
    expect(DispatcherNotification::where('booking_id', $booking->id)->count())->toBe(1);
    expect(collect(pbfPushes())->pluck('title')->all())->toBe(['Booking submitted']);
    expect(pbfPushes()[0]['body'])->toContain('TEST / DEMO');
    expect(Booking::count())->toBe(1);
});

it('submits the booking from the app\'s status check when the webhook is late, by asking PayMongo directly', function () {
    $user = pbfCustomer();
    pbfEnable($user);
    $code = pbfPayAndSubmit($this, $user)->assertCreated()->json('booking_code');
    pbfFakeOutside(['retrieve_paid' => true]);

    $this->getJson("/api/v1/bookings/{$code}/online-payment")->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.booking_submitted', true)
        ->assertJsonPath('data.bookings.0.booking_code', $code);

    expect(Booking::where('booking_code', $code)->value('status'))->toBe('confirmed');
});

it('never submits a booking whose checkout was cancelled, and retries on the same booking without duplicates', function () {
    $user = pbfCustomer();
    pbfEnable($user);
    $code = pbfPayAndSubmit($this, $user)->assertCreated()->json('booking_code');

    $this->postJson("/api/v1/bookings/{$code}/online-payment/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(Booking::includingUnsubmitted()->where('booking_code', $code)->value('status'))->toBe(Booking::STATUS_AWAITING_PAYMENT);

    // Retry from Review: a new session for the SAME booking; never a second booking.
    $this->postJson("/api/v1/bookings/{$code}/online-payment/checkout", ['method' => 'gcash'])->assertCreated()->assertJsonPath('data.status', 'pending');
    $this->postJson("/api/v1/bookings/{$code}/online-payment/checkout", ['method' => 'gcash'])->assertOk(); // double tap: same session

    expect(Booking::includingUnsubmitted()->count())->toBe(1);
    expect(PaymentTransaction::where('status', 'pending')->count())->toBe(1);
    expect(PaymentTransaction::count())->toBe(2);
});

it('closes an unpaid attempt when the customer submits another booking, so it can never be paid alongside it', function () {
    $user = pbfCustomer();
    pbfEnable($user);
    $firstCode = pbfPayAndSubmit($this, $user)->assertCreated()->json('booking_code');
    $firstTransaction = PaymentTransaction::firstOrFail();

    $cash = pbfSubmit($this, $user, ['preferred_payment_method' => 'cash'])->assertCreated();

    expect(Booking::includingUnsubmitted()->where('booking_code', $firstCode)->value('status'))->toBe(Booking::STATUS_PAYMENT_ABANDONED);
    expect($firstTransaction->fresh()->status)->toBe('cancelled');
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/expire'));
    expect(Booking::where('booking_code', $cash->json('booking_code'))->value('status'))->toBe('requested');

    // A payment that still lands on the closed session is kept for review; the abandoned booking is never submitted.
    pbfPaidWebhook($this, $firstTransaction)->assertOk()->assertJsonPath('outcome', 'review_paid_after_close');
    expect(Booking::includingUnsubmitted()->where('booking_code', $firstCode)->value('status'))->toBe(Booking::STATUS_PAYMENT_ABANDONED);
});

it('submits an earlier attempt that turns out to be paid instead of creating a second booking', function () {
    $user = pbfCustomer();
    pbfEnable($user);
    $firstCode = pbfPayAndSubmit($this, $user)->assertCreated()->json('booking_code');
    pbfFakeOutside(['retrieve_paid' => true]); // paid at PayMongo, webhook not here yet

    pbfPayAndSubmit($this, $user)->assertStatus(409)
        ->assertJsonPath('reason', 'earlier_booking_paid')
        ->assertJsonPath('booking_code', $firstCode);

    expect(Booking::where('booking_code', $firstCode)->value('status'))->toBe('confirmed');
    expect(Booking::includingUnsubmitted()->count())->toBe(1);
});

it('lets any active Customer pay online without a demo allow-list, however they sign in', function (array $account) {
    $original = pbfCustomer(); // the original demo account stays allow-listed
    $other = pbfCustomer();
    $other->forceFill($account)->save();
    pbfEnable($original);
    Sanctum::actingAs($other, ['*']);

    $this->getJson('/api/v1/online-payment/availability')->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.channels.gcash', true);
    $code = pbfPayAndSubmit($this, $other)->assertCreated()->assertJsonPath('payment.status', 'pending')->json('booking_code');
    $transaction = PaymentTransaction::firstOrFail();

    pbfPaidWebhook($this, $transaction)->assertOk()->assertJsonPath('outcome', 'paid');
    pbfPaidWebhook($this, $transaction)->assertOk()->assertJsonPath('outcome', 'replay');

    $booking = Booking::where('booking_code', $code)->firstOrFail();
    expect($booking->status)->toBe('confirmed');
    expect($booking->is_demo)->toBeTrue();
    expect(Booking::includingUnsubmitted()->count())->toBe(1);
    expect(Booking::query()->excludingDemo()->count())->toBe(0);
})->with([
    'email and password' => [['auth_provider' => 'password']],
    'Google Sign-In' => [['auth_provider' => 'google', 'google_sub' => 'google-sub-pbf-0001']],
]);

it('still lets the original demo account pay online', function () {
    $original = pbfCustomer();
    pbfEnable($original);

    pbfPayAndSubmit($this, $original)->assertCreated()->assertJsonPath('payment.status', 'pending');
    expect(Booking::includingUnsubmitted()->firstOrFail()->is_demo)->toBeTrue();
});

it('marks every vehicle of a non-allow-listed Customer online group as demo', function () {
    $user = pbfCustomer();
    pbfEnable();
    $payload = pbfRequestPayload();
    Sanctum::actingAs($user, ['*']);

    $this->post('/api/v1/bookings', $payload + [
        'preferred_payment_method' => 'gcash', 'pay_online' => 1,
        'extra_vehicles' => json_encode([['vehicle_type_id' => $payload['vehicle_type_id']]]),
        'extra_vehicle_images' => [0 => [UploadedFile::fake()->image('pbf-extra.jpg', 300, 300)]],
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('payment.status', 'pending');

    expect(Booking::includingUnsubmitted()->pluck('is_demo')->all())->toBe([true, true]);
});

it('keeps a Cash booking real for a Customer who is not a demo account, and it can never be paid online', function () {
    $user = pbfCustomer();
    pbfEnable();

    $code = pbfSubmit($this, $user, ['preferred_payment_method' => 'cash'])->assertCreated()->json('booking_code');

    $booking = Booking::where('booking_code', $code)->firstOrFail();
    expect($booking->is_demo)->toBeFalse();
    expect($booking->status)->toBe('requested');
    expect(Booking::query()->excludingDemo()->count())->toBe(1);
    $this->postJson("/api/v1/bookings/{$code}/online-payment/checkout", ['method' => 'gcash'])->assertStatus(409);
    expect(PaymentTransaction::count())->toBe(0);
});

it('refuses online payment for staff roles and disabled accounts', function (string $role, array $state) {
    $user = pbfCustomer();
    $user->forceFill(['role_id' => pbfRole($role === 'Customer' ? 5 : 2, $role)->id] + $state)->save();
    pbfEnable($user); // being on the demo list does not override this
    $guardUser = $user->fresh();

    expect(app(\App\Services\OnlinePayments\OnlinePaymentService::class)->canPayAtBooking($guardUser))->toBeFalse();

    Sanctum::actingAs($guardUser, ['*']);
    expect(pbfPayAndSubmit($this, $guardUser)->status())->toBeIn([401, 403, 422]);
    expect(Booking::includingUnsubmitted()->count())->toBe(0);
    expect(PaymentTransaction::count())->toBe(0);
})->with([
    'dispatcher' => ['Dispatcher', []],
    'inactive customer' => ['Customer', ['status' => 'inactive']],
    'archived customer' => ['Customer', ['archived_at' => '2026-11-01 00:00:00']],
]);

it('requires a signed-in user for availability and booking', function () {
    pbfEnable();

    $this->getJson('/api/v1/online-payment/availability')->assertUnauthorized();
    $this->postJson('/api/v1/bookings', ['pay_online' => 1])->assertUnauthorized();
});

it('still refuses a live key for every Customer', function () {
    $user = pbfCustomer();
    pbfEnable();
    config(['paymongo.secret_key' => 'sk_live_must_never_be_used_123']);
    Sanctum::actingAs($user, ['*']);

    $this->getJson('/api/v1/online-payment/availability')->assertJsonPath('data.available', false);
    pbfPayAndSubmit($this, $user)->assertStatus(422)->assertJsonPath('reason', 'online_payment_unavailable');
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'paymongo'));
});

it('keeps Pay & Submit to GCash Book Now requests', function () {
    $user = pbfCustomer();
    pbfEnable();

    pbfSubmit($this, $user, ['preferred_payment_method' => 'bank_transfer', 'pay_online' => 1])->assertStatus(422);
    pbfSubmit($this, $user, ['preferred_payment_method' => 'gcash', 'pay_online' => 1, 'service_type' => 'schedule', 'scheduled_date' => now()->addDays(2)->toDateString(), 'scheduled_time' => '10:00'])->assertStatus(422);

    expect(Booking::includingUnsubmitted()->count())->toBe(0);
    expect(PaymentTransaction::count())->toBe(0);
});

it('keeps Online Banking off by default, and uses only the configured bank channel once it is switched on', function () {
    $user = pbfCustomer();
    pbfEnable($user);
    Sanctum::actingAs($user, ['*']);
    $this->getJson('/api/v1/online-payment/availability')->assertJsonPath('data.channels.bank_transfer', false);

    config(['paymongo.channels' => ['gcash' => 'gcash', 'bank_transfer' => 'dob']]);
    $this->getJson('/api/v1/online-payment/availability')->assertJsonPath('data.channels.bank_transfer', true);
    pbfSubmit($this, $user, ['preferred_payment_method' => 'bank_transfer', 'pay_online' => 1])->assertCreated()
        ->assertJsonPath('payment.method', 'bank_transfer');

    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/checkout_sessions') && $r['data']['attributes']['payment_method_types'] === ['dob']);
    expect(Booking::includingUnsubmitted()->value('status'))->toBe(Booking::STATUS_AWAITING_PAYMENT);
});

// ───────────────────────── Team Leader: online-paid ─────────────────────────

function pbfPrepaidAtDropoff($test): array
{
    $user = pbfCustomer();
    pbfEnable($user);
    $code = pbfPayAndSubmit($test, $user)->assertCreated()->json('booking_code');
    $transaction = PaymentTransaction::firstOrFail();
    pbfPaidWebhook($test, $transaction)->assertJsonPath('outcome', 'paid');

    return [...pbfAtDropoff(Booking::where('booking_code', $code)->firstOrFail()), $transaction, $user];
}

it('lets the Team Leader complete a verified prepaid booking directly: no cash, photo, signature or dispatcher step', function () {
    [$booking, $teamLeader, $unit, $transaction] = pbfPrepaidAtDropoff($this);
    Sanctum::actingAs($teamLeader, ['*']);

    $this->getJson('/api/v1/team-leader/task')->assertOk()
        ->assertJsonPath('data.payment.mode', 'online_prepaid')
        ->assertJsonPath('data.payment.test_mode', true);

    $this->post("/api/v1/team-leader/task/{$booking->booking_code}/complete", [], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.status', 'completed');

    $booking->refresh();
    expect($booking->status)->toBe('completed');
    expect($booking->completed_at)->not->toBeNull();
    expect($booking->payment_method)->toBe('gcash');
    expect($booking->cash_received)->toBeNull();
    expect($booking->customer_signature_path)->toBeNull();
    expect($unit->fresh()->status)->toBe('available');
    expect($transaction->fresh()->status)->toBe('paid');
    expect(DispatcherNotification::where('type', DispatcherNotification::TYPE_VERIFICATION_REQUIRED)->count())->toBe(0);
    expect(AuditLog::where('action', 'prepaid_task_completed')->count())->toBe(1);

    // A repeated tap is harmless.
    $this->post("/api/v1/team-leader/task/{$booking->booking_code}/complete", [], ['Accept' => 'application/json'])->assertOk();
    expect(AuditLog::where('action', 'prepaid_task_completed')->count())->toBe(1);
});

it('recognises a payment made under the old pay-after-towing flow (like TM-00153) and completes without collecting again', function () {
    // Legacy shape: a submitted booking at the drop-off, with a verified post-tow transaction (no pay_at_booking marker).
    $user = pbfCustomer();
    pbfEnable($user);
    $booking = Booking::where('booking_code', pbfSubmit($this, $user, ['preferred_payment_method' => 'gcash'])->json('booking_code'))->firstOrFail();
    [$booking, $teamLeader] = pbfAtDropoff($booking);
    PaymentTransaction::create([
        'provider' => 'paymongo', 'reference' => 'TMP-LEGACY0000001', 'payable_type' => 'booking', 'payable_id' => $booking->id,
        'booking_id' => $booking->id, 'customer_id' => $booking->customer_id, 'method' => 'gcash',
        'amount_centavos' => (int) round((float) $booking->final_total * 100), 'currency' => 'PHP',
        'payable_snapshot' => ['type' => 'booking', 'anchor_id' => $booking->id, 'member_ids' => [$booking->id], 'active_ids' => [$booking->id]],
        'status' => 'paid', 'livemode' => false, 'provider_payment_id' => 'pay_legacy_1', 'paid_at' => now(),
    ]);
    Sanctum::actingAs($teamLeader, ['*']);

    $this->getJson('/api/v1/team-leader/task')->assertJsonPath('data.payment.mode', 'online_prepaid');
    $this->post("/api/v1/team-leader/task/{$booking->booking_code}/complete", [], ['Accept' => 'application/json'])->assertOk();

    expect($booking->fresh()->status)->toBe('completed');
    expect($booking->fresh()->cash_received)->toBeNull();
    expect(PaymentTransaction::count())->toBe(1);
});

it('charges a two-vehicle request once at Step 3 and completes the whole group directly once both vehicles arrive', function () {
    $user = pbfCustomer();
    pbfEnable($user);
    $payload = pbfRequestPayload();
    Sanctum::actingAs($user, ['*']);
    $code = $this->post('/api/v1/bookings', $payload + [
        'preferred_payment_method' => 'gcash', 'pay_online' => 1,
        'extra_vehicles' => json_encode([['vehicle_type_id' => $payload['vehicle_type_id']]]),
        'extra_vehicle_images' => [0 => [UploadedFile::fake()->image('pbf-extra.jpg', 300, 300)]],
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('payment.status', 'pending')->json('booking_code');

    $transaction = PaymentTransaction::firstOrFail();
    $members = Booking::includingUnsubmitted()->orderBy('id')->get();
    expect($members)->toHaveCount(2);
    expect($transaction->amount_centavos)->toBe((int) round($members->sum(fn ($b) => (float) $b->final_total) * 100)); // server total, every vehicle

    pbfPaidWebhook($this, $transaction)->assertJsonPath('outcome', 'paid');
    [$first, $leader] = pbfAtDropoff(Booking::where('booking_code', $code)->firstOrFail());
    Sanctum::actingAs($leader, ['*']);
    $this->post("/api/v1/team-leader/task/{$first->booking_code}/complete", [], ['Accept' => 'application/json'])->assertStatus(422); // vehicle 2 not there yet

    pbfAtDropoff(Booking::whereKeyNot($first->id)->firstOrFail());
    Sanctum::actingAs($leader, ['*']);
    $this->post("/api/v1/team-leader/task/{$first->booking_code}/complete", [], ['Accept' => 'application/json'])->assertOk();

    expect(Booking::orderBy('id')->pluck('status')->all())->toBe(['completed', 'completed']);
    expect(\App\Models\Invoice::where('is_current', true)->count())->toBe(1);
    expect((int) round((float) \App\Models\Invoice::firstOrFail()->total * 100))->toBe($transaction->amount_centavos);
});

it('blocks both direct completion and cash collection when the online payment no longer covers the total', function () {
    [$booking, $teamLeader] = pbfPrepaidAtDropoff($this);
    $booking->update(['final_total' => (float) $booking->final_total + 500]); // e.g. a fee added after payment
    Sanctum::actingAs($teamLeader, ['*']);

    $this->getJson('/api/v1/team-leader/task')->assertJsonPath('data.payment.mode', 'online_review');
    $this->post("/api/v1/team-leader/task/{$booking->booking_code}/complete", [
        'payment_method' => 'cash', 'cash_received' => 99999, 'signature' => UploadedFile::fake()->image('sig.png'),
    ], ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('reason', 'online_payment_review');

    expect($booking->fresh()->status)->toBe('arrived_dropoff');
});

it('never treats an unpaid or client-claimed online payment as prepaid', function () {
    $user = pbfCustomer();
    [$booking, $teamLeader] = pbfAtDropoff(Booking::where('booking_code', pbfSubmit($this, $user, ['preferred_payment_method' => 'gcash'])->json('booking_code'))->firstOrFail());
    Sanctum::actingAs($teamLeader, ['*']);

    $this->getJson('/api/v1/team-leader/task')->assertJsonPath('data.payment.mode', 'cash');
    // "Completing" without the cash evidence is refused; the app cannot claim it was paid online.
    $this->post("/api/v1/team-leader/task/{$booking->booking_code}/complete", ['payment_method' => 'gcash', 'paid' => true], ['Accept' => 'application/json'])->assertStatus(422);
    expect($booking->fresh()->status)->toBe('arrived_dropoff');
});

// ───────────────────────── Team Leader: cash ─────────────────────────

it('requires amount, payment photo and signature for cash, then waits for the dispatcher to complete it', function () {
    $user = pbfCustomer();
    [$booking, $teamLeader] = pbfAtDropoff(Booking::where('booking_code', pbfSubmit($this, $user, ['preferred_payment_method' => 'cash'])->json('booking_code'))->firstOrFail());
    $url = "/api/v1/team-leader/task/{$booking->booking_code}/complete";
    $total = (float) $booking->final_total;
    Sanctum::actingAs($teamLeader, ['*']);

    $headers = ['Accept' => 'application/json'];
    $this->post($url, ['cash_received' => $total], $headers)->assertStatus(422); // no signature
    $this->post($url, ['signature' => UploadedFile::fake()->image('sig.png')], $headers)->assertStatus(422); // no amount
    $this->post($url, ['cash_received' => 0, 'signature' => UploadedFile::fake()->image('sig.png')], $headers)->assertStatus(422); // not positive
    $this->post($url, ['cash_received' => $total, 'signature' => UploadedFile::fake()->image('sig.png')], $headers)->assertStatus(422); // no photo
    $booking->update(['payment_proof_path' => 'task-photos/pbf-proof.jpg']);
    $this->post($url, ['cash_received' => $total - 1, 'signature' => UploadedFile::fake()->image('sig.png')], $headers)->assertStatus(422); // short
    $this->post($url, ['payment_method' => 'gcash', 'cash_received' => $total, 'signature' => UploadedFile::fake()->image('sig.png')], $headers)->assertStatus(422); // not collected by TL
    expect($booking->fresh()->status)->toBe('arrived_dropoff');

    $this->post($url, ['cash_received' => $total + 100, 'signature' => UploadedFile::fake()->image('sig.png')], $headers)->assertOk();
    $this->post($url, ['cash_received' => $total + 100, 'signature' => UploadedFile::fake()->image('sig.png')], $headers)->assertOk(); // duplicate tap

    $booking->refresh();
    expect($booking->status)->toBe('waiting_verification');
    expect($booking->payment_method)->toBe('cash');
    expect((float) $booking->cash_received)->toBe($total + 100);
    expect(DispatcherNotification::where('type', DispatcherNotification::TYPE_VERIFICATION_REQUIRED)->where('booking_id', $booking->id)->count())->toBe(1);

    $dispatcher = User::factory()->create(['role_id' => pbfRole(2, 'Dispatcher')->id, 'status' => 'active', 'must_change_password' => false]);
    $this->actingAs($dispatcher)->post(route('admin.jobs.confirm-payment', $booking))->assertOk();
    expect($booking->fresh()->status)->toBe('completed');
});

// ───────────────────────── Push: Team Leader assignment ─────────────────────────

it('pushes "New Towing Assignment" to the assigned Team Leader only, once per assignment', function () {
    pbfConfigureFcm();
    $assigned = pbfTeamLeader();
    $other = pbfTeamLeader();
    DeviceToken::create(['user_id' => $assigned->id, 'token' => 'tl-assigned-device-0000000000001', 'platform' => 'android']);
    DeviceToken::create(['user_id' => $other->id, 'token' => 'tl-other-device-000000000000000001', 'platform' => 'android']);
    $dispatcher = User::factory()->create(['role_id' => pbfRole(2, 'Dispatcher')->id, 'status' => 'active']);
    $booking = Booking::where('booking_code', pbfSubmit($this, pbfCustomer(), ['preferred_payment_method' => 'cash'])->json('booking_code'))->firstOrFail();
    Http::swap(new \Illuminate\Http\Client\Factory());
    pbfFakeOutside();

    $this->actingAs($dispatcher);
    $booking->update(['status' => 'assigned', 'assigned_team_leader_id' => $assigned->id, 'assigned_at' => now()]);
    $booking->update(['dispatcher_note' => 'Gate 2']); // same assignment saved again: no second push

    $pushes = pbfPushes();
    expect($pushes)->toHaveCount(1);
    expect($pushes[0])->toMatchArray(['title' => 'New Towing Assignment', 'body' => 'You have received a new towing task. Tap to view the details.', 'token' => 'tl-assigned-device-0000000000001']);
    expect($pushes[0]['data'])->toMatchArray(['type' => 'tl_assignment', 'booking_code' => $booking->booking_code]);

    // Returned and reassigned: a new assignment, a new push.
    $booking->update(['status' => 'returned']);
    $this->travel(1)->minutes();
    $booking->update(['status' => 'assigned', 'assigned_team_leader_id' => $assigned->id, 'assigned_at' => now()]);
    expect(pbfPushes())->toHaveCount(2);
    expect(collect(pbfPushes())->pluck('token')->unique()->all())->toBe(['tl-assigned-device-0000000000001']);
});

it('drops a Team Leader device token that FCM reports as no longer registered', function () {
    pbfConfigureFcm();
    $teamLeader = pbfTeamLeader();
    DeviceToken::create(['user_id' => $teamLeader->id, 'token' => 'stale-tl-device-00000000000000001', 'platform' => 'android']);
    $booking = Booking::where('booking_code', pbfSubmit($this, pbfCustomer(), ['preferred_payment_method' => 'cash'])->json('booking_code'))->firstOrFail();

    $this->actingAs(User::factory()->create(['role_id' => pbfRole(2, 'Dispatcher')->id]));
    $booking->update(['status' => 'assigned', 'assigned_team_leader_id' => $teamLeader->id, 'assigned_at' => now()]);

    expect(DeviceToken::where('user_id', $teamLeader->id)->count())->toBe(0);
});

it('lets a Team Leader register a device token for assignment pushes', function () {
    $teamLeader = pbfTeamLeader();
    Sanctum::actingAs($teamLeader, ['*']);

    $this->postJson('/api/v1/device-tokens', ['token' => 'tl-registered-device-0000000000001', 'platform' => 'android'])->assertOk();

    expect(DeviceToken::where('user_id', $teamLeader->id)->value('token'))->toBe('tl-registered-device-0000000000001');
});
