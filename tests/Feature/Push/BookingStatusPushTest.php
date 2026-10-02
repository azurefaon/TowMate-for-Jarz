<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Services\Push\BookingStatusPush;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

const BSP_LAT = 14.6760;
const BSP_LNG = 121.0437;

function bspRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function bspConfigureFcm(): void
{
    // Throwaway key generated per run; never a real credential. On Windows PHP
    // builds without a default openssl.cnf, fall back to a bundled one.
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
        'services.fcm.service_account_base64' => base64_encode(json_encode([
            'client_email' => 'push@towmate-test.iam.gserviceaccount.com',
            'private_key'  => $pem,
            'project_id'   => 'towmate-test',
        ])),
        'services.fcm.project_id' => 'towmate-test',
    ]);
    Cache::flush();
}

/** @return array{0: User, 1: User, 2: Booking} [teamLeader, customerUser, booking] */
function bspScenario(string $status = 'accepted', ?string $groupCode = null): array
{
    $teamLeader = User::factory()->create(['role_id' => bspRole(3, 'Team Leader')->id, 'must_change_password' => false]);
    $customerUser = User::factory()->create(['role_id' => bspRole(4, 'Customer')->id]);

    $truckType = TruckType::create(['name' => 'BSP Truck ' . uniqid(), 'base_rate' => 1500, 'per_km_rate' => 300]);
    $unit = Unit::create([
        'name' => 'BSP Unit ' . uniqid(),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
    ]);
    $customer = Customer::create([
        'user_id' => $customerUser->id,
        'full_name' => 'BSP Customer',
        'phone' => '09171234567',
        'email' => 'bsp-' . uniqid() . '@example.com',
    ]);

    $booking = bspBooking($customer, $teamLeader, $unit, $truckType, $status, $groupCode);

    return [$teamLeader, $customerUser, $booking];
}

function bspBooking(Customer $customer, User $teamLeader, Unit $unit, TruckType $truckType, string $status, ?string $groupCode = null): Booking
{
    return Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $teamLeader->id,
        'group_code' => $groupCode,
        'pickup_address' => 'Quezon City Test Pickup',
        'pickup_lat' => BSP_LAT,
        'pickup_lng' => BSP_LNG,
        'dropoff_address' => 'Makati Test Dropoff',
        'dropoff_lat' => 14.5547,
        'dropoff_lng' => 121.0244,
        'distance_km' => 12.5,
        'base_rate' => 1500,
        'per_km_rate' => 300,
        'computed_total' => 4050,
        'final_total' => 4536,
        'status' => $status,
        'assigned_at' => now(),
    ]);
}

function bspStatusUrl(Booking $booking): string
{
    return '/api/v1/team-leader/task/' . $booking->booking_code . '/status';
}

function bspFakeFcm(array $fcmResponses = null): void
{
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600]),
        'fcm.googleapis.com/*'    => $fcmResponses ? Http::sequence($fcmResponses) : Http::response(['name' => 'projects/x/messages/1']),
    ]);
}

function bspSentMessages(): array
{
    return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'fcm.googleapis.com'))
        ->map(fn ($pair) => $pair[0]->data()['message'])
        ->values()
        ->all();
}

beforeEach(fn () => bspConfigureFcm());

it('14/20: a real TL transition pushes exactly once to the booking customer\'s device', function () {
    bspFakeFcm();
    [$tl, $customerUser, $booking] = bspScenario('accepted');
    $other = User::factory()->create(['role_id' => bspRole(4, 'Customer')->id]);
    DeviceToken::create(['user_id' => $customerUser->id, 'token' => 'customer-device-token-0001']);
    DeviceToken::create(['user_id' => $other->id, 'token' => 'unrelated-user-device-0002']);

    Sanctum::actingAs($tl, ['*']);
    $this->patchJson(bspStatusUrl($booking), ['status' => 'on_the_way'])->assertOk();

    $sent = bspSentMessages();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['token'])->toBe('customer-device-token-0001')
        ->and($sent[0]['notification'])->toBe(['title' => 'Tow truck on the way', 'body' => 'Your tow truck is on the way.'])
        ->and($sent[0]['data'])->toBe([
            'type' => 'booking_status',
            'booking_id' => (string) $booking->id,
            'booking_code' => $booking->booking_code,
            'status' => 'on_the_way',
        ])
        ->and($sent[0]['android']['notification']['channel_id'])->toBe('towmate_booking_updates');
});

it('payload carries no addresses, phone, email or money', function () {
    bspFakeFcm();
    [$tl, $customerUser, $booking] = bspScenario('accepted');
    DeviceToken::create(['user_id' => $customerUser->id, 'token' => 'customer-device-token-0001']);

    Sanctum::actingAs($tl, ['*']);
    $this->patchJson(bspStatusUrl($booking), ['status' => 'on_the_way'])->assertOk();

    $json = json_encode(bspSentMessages());
    foreach (['Quezon City', 'Makati', '09171234567', '@example.com', '4536'] as $leak) {
        expect($json)->not->toContain($leak);
    }
});

it('accept pushes "accepted" once', function () {
    bspFakeFcm();
    [$tl, $customerUser, $booking] = bspScenario('assigned');
    DeviceToken::create(['user_id' => $customerUser->id, 'token' => 'customer-device-token-0001']);

    Sanctum::actingAs($tl, ['*']);
    $this->postJson('/api/v1/team-leader/task/' . $booking->booking_code . '/accept')->assertOk();

    $sent = bspSentMessages();
    expect($sent)->toHaveCount(1)->and($sent[0]['data']['status'])->toBe('accepted');
});

it('15: an unchanged/rejected transition sends zero pushes', function () {
    bspFakeFcm();
    [$tl, $customerUser, $booking] = bspScenario('on_the_way');
    DeviceToken::create(['user_id' => $customerUser->id, 'token' => 'customer-device-token-0001']);

    Sanctum::actingAs($tl, ['*']);
    $this->patchJson(bspStatusUrl($booking), ['status' => 'on_the_way'])->assertStatus(422);

    expect(bspSentMessages())->toBeEmpty();

    app(BookingStatusPush::class)->notify($booking->fresh(), 'on_the_way');
    expect(bspSentMessages())->toBeEmpty();
});

it('16: internal/unmapped statuses never push', function () {
    bspFakeFcm();
    [$tl, $customerUser, $booking] = bspScenario('in_progress');
    DeviceToken::create(['user_id' => $customerUser->id, 'token' => 'customer-device-token-0001']);

    Sanctum::actingAs($tl, ['*']);
    $this->patchJson(bspStatusUrl($booking), ['status' => 'loading_vehicle'])->assertOk();
    expect($booking->fresh()->status)->toBe('loading_vehicle');

    foreach (['assigned', 'loading_vehicle', 'waiting_verification', 'payment_pending', 'payment_submitted', 'delayed', 'returned'] as $status) {
        expect(array_key_exists($status, BookingStatusPush::MESSAGES))->toBeFalse($status);
    }
    expect(bspSentMessages())->toBeEmpty();
});

it('17: a transition that does not commit (invalid / unauthorized) pushes nothing', function () {
    bspFakeFcm();
    [$tl, $customerUser, $booking] = bspScenario('accepted');
    DeviceToken::create(['user_id' => $customerUser->id, 'token' => 'customer-device-token-0001']);

    Sanctum::actingAs($tl, ['*']);
    // accepted -> completed is not a valid TL transition: closure returns 422, nothing persisted.
    $this->patchJson(bspStatusUrl($booking), ['status' => 'completed'])->assertStatus(422);
    // arrival without location is rejected inside the transaction.
    $this->patchJson(bspStatusUrl(tap($booking)->update(['status' => 'on_the_way'])), ['status' => 'arrived_pickup'])->assertStatus(422);

    expect($booking->fresh()->status)->toBe('on_the_way')->and(bspSentMessages())->toBeEmpty();
});

it('18: FCM failure (HTTP error or connection exception) never fails the status transition', function () {
    [$tl, $customerUser, $booking] = bspScenario('accepted');
    DeviceToken::create(['user_id' => $customerUser->id, 'token' => 'customer-device-token-0001']);

    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600]),
        'fcm.googleapis.com/*'    => Http::response(['error' => ['message' => 'boom']], 500),
    ]);
    Sanctum::actingAs($tl, ['*']);
    $this->patchJson(bspStatusUrl($booking), ['status' => 'on_the_way'])->assertOk()->assertJsonPath('success', true);
    expect($booking->fresh()->status)->toBe('on_the_way')
        ->and(DeviceToken::count())->toBe(1);

    Cache::flush();
    Http::fake(fn () => throw new ConnectionException('network down'));
    $this->patchJson(bspStatusUrl($booking), ['status' => 'arrived_pickup', 'lat' => BSP_LAT, 'lng' => BSP_LNG])->assertOk();
    expect($booking->fresh()->status)->toBe('arrived_pickup');
});

it('unconfigured FCM or no registered device is a silent no-op', function () {
    Http::fake();
    [$tl, , $booking] = bspScenario('accepted');

    Sanctum::actingAs($tl, ['*']);
    $this->patchJson(bspStatusUrl($booking), ['status' => 'on_the_way'])->assertOk();
    expect(bspSentMessages())->toBeEmpty();

    config(['services.fcm.service_account_base64' => null]);
    $this->patchJson(bspStatusUrl($booking), ['status' => 'accepted'])->assertOk();
    expect(Http::recorded())->toHaveCount(0);
});

it('13/19: an invalid token is removed without blocking the customer\'s other device', function () {
    [$tl, $customerUser, $booking] = bspScenario('accepted');
    DeviceToken::create(['user_id' => $customerUser->id, 'token' => 'stale-device-token-000001']);
    DeviceToken::create(['user_id' => $customerUser->id, 'token' => 'good-device-token-0000002']);

    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600]),
        'fcm.googleapis.com/*'    => Http::sequence()
            ->push(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404)
            ->push(['name' => 'projects/x/messages/2']),
    ]);

    Sanctum::actingAs($tl, ['*']);
    $this->patchJson(bspStatusUrl($booking), ['status' => 'on_the_way'])->assertOk();

    expect(bspSentMessages())->toHaveCount(2)
        ->and(DeviceToken::pluck('token')->all())->toBe(['good-device-token-0000002']);
});

it('21: a multi-vehicle group gets one push per status event, not one per vehicle', function () {
    bspFakeFcm();
    [$tl, $customerUser, $first] = bspScenario('accepted', 'GRP-PUSH-1');
    $second = bspBooking($first->customer, $tl, $first->assignedUnit ?? Unit::first(), $first->truckType ?? TruckType::first(), 'accepted', 'GRP-PUSH-1');
    DeviceToken::create(['user_id' => $customerUser->id, 'token' => 'customer-device-token-0001']);

    Sanctum::actingAs($tl, ['*']);
    $this->patchJson(bspStatusUrl($first), ['status' => 'on_the_way'])->assertOk();
    $this->patchJson(bspStatusUrl($second), ['status' => 'on_the_way'])->assertOk();

    expect(bspSentMessages())->toHaveCount(1);

    $this->patchJson(bspStatusUrl($first), ['status' => 'arrived_pickup', 'lat' => BSP_LAT, 'lng' => BSP_LNG])->assertOk();
    expect(bspSentMessages())->toHaveCount(2);
});

it('22: completion push comes from dispatcher payment confirmation, once', function () {
    bspFakeFcm();
    [, $customerUser, $booking] = bspScenario('waiting_verification');
    $booking->update(['payment_submitted_at' => now()]);
    DeviceToken::create(['user_id' => $customerUser->id, 'token' => 'customer-device-token-0001']);

    $dispatcher = User::factory()->create(['role_id' => bspRole(2, 'Dispatcher')->id, 'status' => 'active']);
    $this->actingAs($dispatcher);

    $this->postJson(route('admin.jobs.confirm-payment', $booking))->assertOk();
    $this->postJson(route('admin.jobs.confirm-payment', $booking))->assertOk(); // already confirmed

    $sent = bspSentMessages();
    expect($booking->fresh()->status)->toBe('completed')
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]['data']['status'])->toBe('completed')
        ->and($sent[0]['notification']['title'])->toBe('Towing complete');
});

it('demo arrival simulation never sends an FCM push', function () {
    bspFakeFcm();
    config(['services.fcm.project_id' => 'towmate-test']);
    [, $customerUser, $booking] = bspScenario('on_the_way');
    DeviceToken::create(['user_id' => $customerUser->id, 'token' => 'customer-device-token-0001']);

    // The demo controller is the only demo path; it must not reference the push service.
    $source = file_get_contents(app_path('Http/Controllers/Api/TeamLeader/TLDemoController.php'));
    expect($source)->not->toContain('BookingStatusPush')->and(bspSentMessages())->toBeEmpty();
});

it('canonical status → notification mapping', function () {
    expect(BookingStatusPush::MESSAGES)->toBe([
        'accepted'        => ['Towing job accepted', 'Your assigned team has accepted your towing request.'],
        'on_the_way'      => ['Tow truck on the way', 'Your tow truck is on the way.'],
        'arrived_pickup'  => ['Tow truck arrived', 'Your tow truck has arrived at the pickup location.'],
        'in_progress'     => ['Towing in progress', 'Your towing service is in progress.'],
        'on_job'          => ['On the way to drop-off', 'Your vehicle is on its way to the drop-off location.'],
        'arrived_dropoff' => ['Arrived at destination', 'Your vehicle has arrived at the destination.'],
        'completed'       => ['Towing complete', 'Your towing request is complete.'],
    ]);
});
