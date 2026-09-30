<?php

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Support\TlDemoFixture;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

function simSeedDemo(): array
{
    Artisan::call('dev:seed-tl-assigned-team-demo');
    $tl = User::where('email', TlDemoFixture::TL_EMAIL)->firstOrFail();
    $booking = Booking::where('assigned_team_leader_id', $tl->id)->firstOrFail();

    return [$tl, $booking];
}

function simSimulate(User $as, Booking $booking, array $body = [])
{
    Sanctum::actingAs($as);

    return test()->postJson("/api/v1/team-leader/demo/task/{$booking->booking_code}/simulate-arrival", $body);
}

function simOtherTeamLeader(): array
{
    $role = Role::find(3) ?? tap(new Role(['name' => 'Team Leader', 'description' => 'TL']), function ($r) {
        $r->id = 3;
        $r->save();
    });
    $tl = User::factory()->create(['role_id' => $role->id, 'must_change_password' => false]);
    $type = TruckType::create(['name' => 'Sim Truck ' . uniqid(), 'class' => 'light', 'base_rate' => 1500, 'per_km_rate' => 300]);
    $unit = Unit::create([
        'name' => 'Sim Unit ' . uniqid(),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $type->id,
        'team_leader_id' => $tl->id,
        'status' => 'available',
    ]);
    $customer = Customer::create(['full_name' => 'Other', 'phone' => '09171234567', 'email' => 'o-' . uniqid() . '@example.com']);
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $type->id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $tl->id,
        'pickup_address' => 'A', 'pickup_lat' => 14.6760, 'pickup_lng' => 121.0437,
        'dropoff_address' => 'B', 'dropoff_lat' => 14.5547, 'dropoff_lng' => 121.0244,
        'distance_km' => 9, 'base_rate' => 1500, 'per_km_rate' => 300, 'final_total' => 4000,
        'status' => 'on_the_way',
    ]);

    return [$tl, $booking];
}

it('simulates pickup arrival for the demo TL + demo booking in local/testing', function () {
    [$tl, $booking] = simSeedDemo();
    $booking->update(['status' => 'on_the_way']);

    simSimulate($tl, $booking)->assertOk()->assertJson(['success' => true, 'status' => 'arrived_pickup']);

    expect($booking->fresh()->status)->toBe('arrived_pickup');
    expect(AuditLog::where('action', 'demo_arrival_simulated')->where('entity_id', $booking->id)->count())->toBe(1);
});

it('simulates drop-off arrival from on_job', function () {
    [$tl, $booking] = simSeedDemo();
    $booking->update(['status' => 'on_job']);

    simSimulate($tl, $booking)->assertOk()->assertJson(['status' => 'arrived_dropoff']);

    expect($booking->fresh()->status)->toBe('arrived_dropoff');
});

it('rejects every other lifecycle status with zero mutation', function (string $status) {
    [$tl, $booking] = simSeedDemo();
    $booking->update(['status' => $status]);

    simSimulate($tl, $booking)->assertStatus(422);

    expect($booking->fresh()->status)->toBe($status);
    expect(AuditLog::where('action', 'demo_arrival_simulated')->count())->toBe(0);
})->with(['assigned', 'accepted', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'arrived_dropoff', 'waiting_verification', 'completed', 'returned']);

it('ignores any client-supplied target or bypass parameters', function () {
    [$tl, $booking] = simSeedDemo();
    $booking->update(['status' => 'on_the_way']);

    simSimulate($tl, $booking, ['status' => 'completed', 'skip_location' => true, 'force' => true, 'demo' => true])->assertOk();

    expect($booking->fresh()->status)->toBe('arrived_pickup');
});

it('does not let another Team Leader use it, even on their own booking', function () {
    simSeedDemo();
    [$other, $otherBooking] = simOtherTeamLeader();

    simSimulate($other, $otherBooking)->assertNotFound();

    expect($otherBooking->fresh()->status)->toBe('on_the_way');
});

it('does not let another Team Leader touch the demo booking', function () {
    [, $booking] = simSeedDemo();
    [$other] = simOtherTeamLeader();
    $booking->update(['status' => 'on_the_way']);

    simSimulate($other, $booking)->assertNotFound();

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('does not work on a non-demo booking even for the demo TL', function () {
    [$tl] = simSeedDemo();
    $customer = Customer::create(['full_name' => 'Real', 'phone' => '09171234567', 'email' => 'real-' . uniqid() . '@example.com']);
    $other = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => TruckType::first()->id,
        'assigned_team_leader_id' => $tl->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B',
        'distance_km' => 9, 'base_rate' => 1500, 'per_km_rate' => 300, 'final_total' => 4000,
        'status' => 'on_the_way',
        'dispatcher_note' => 'A real dispatcher note',
    ]);

    simSimulate($tl, $other)->assertNotFound();

    expect($other->fresh()->status)->toBe('on_the_way');
});

it('stops working once the dispatcher reassigns the demo booking to someone else', function () {
    [$tl, $booking] = simSeedDemo();
    [$other] = simOtherTeamLeader();
    $booking->update(['status' => 'on_the_way', 'assigned_team_leader_id' => $other->id]);

    simSimulate($tl, $booking)->assertNotFound();

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('fails closed in production: 404 and zero mutation', function () {
    [$tl, $booking] = simSeedDemo();
    $booking->update(['status' => 'on_the_way']);
    $before = $booking->fresh()->updated_at;

    $original = app()['env'];
    app()['env'] = 'production';
    try {
        simSimulate($tl, $booking)->assertNotFound();
        simSimulate($tl, $booking, ['skip_location' => true])->assertNotFound();
    } finally {
        app()['env'] = $original;
    }

    $fresh = $booking->fresh();
    expect($fresh->status)->toBe('on_the_way')
        ->and($fresh->updated_at->equalTo($before))->toBeTrue()
        ->and(AuditLog::where('action', 'demo_arrival_simulated')->count())->toBe(0);
});

it('also fails closed in staging', function () {
    [$tl, $booking] = simSeedDemo();
    $booking->update(['status' => 'on_the_way']);

    $original = app()['env'];
    app()['env'] = 'staging';
    try {
        simSimulate($tl, $booking)->assertNotFound();
    } finally {
        app()['env'] = $original;
    }

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('requires authentication', function () {
    [, $booking] = simSeedDemo();
    $booking->update(['status' => 'on_the_way']);

    $this->postJson("/api/v1/team-leader/demo/task/{$booking->booking_code}/simulate-arrival")->assertUnauthorized();

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('leaves the real arrival endpoint fully location-validated', function () {
    [$tl, $booking] = simSeedDemo();
    $booking->update(['status' => 'on_the_way']);
    Sanctum::actingAs($tl);
    $url = "/api/v1/team-leader/task/{$booking->booking_code}/status";

    // No location at all.
    $this->patchJson($url, ['status' => 'arrived_pickup'])->assertStatus(422);
    // Far from the pickup (the drop-off coordinates).
    $this->patchJson($url, ['status' => 'arrived_pickup', 'lat' => 14.5547, 'lng' => 121.0244])->assertStatus(422);
    // The removed is_demo flag is ignored: it is just another location-less request.
    $this->patchJson($url, ['status' => 'arrived_pickup', 'is_demo' => true])->assertStatus(422);

    expect($booking->fresh()->status)->toBe('on_the_way');

    // A genuinely-near location still works exactly as before.
    $this->patchJson($url, ['status' => 'arrived_pickup', 'lat' => 14.6760, 'lng' => 121.0437])->assertOk();
    expect($booking->fresh()->status)->toBe('arrived_pickup');
});

it('does not touch group siblings', function () {
    [$tl, $booking] = simSeedDemo();
    $booking->update(['status' => 'on_the_way', 'group_code' => 'SIM-GROUP-1']);
    $sibling = Booking::create([
        'customer_id' => $booking->customer_id,
        'truck_type_id' => $booking->truck_type_id,
        'pickup_address' => 'A', 'dropoff_address' => 'B',
        'distance_km' => 9, 'base_rate' => 1500, 'per_km_rate' => 300, 'final_total' => 4000,
        'group_code' => 'SIM-GROUP-1',
        'status' => 'requested',
    ]);

    simSimulate($tl, $booking)->assertOk();

    expect($booking->fresh()->status)->toBe('arrived_pickup')
        ->and($sibling->fresh()->status)->toBe('requested')
        ->and($sibling->fresh()->assigned_team_leader_id)->toBeNull();
});

it('exposes demo_arrival_available only to the demo fixture at a simulatable stage', function () {
    [$tl, $booking] = simSeedDemo();

    foreach (['assigned' => false, 'accepted' => true, 'on_the_way' => true, 'arrived_pickup' => false, 'on_job' => true, 'arrived_dropoff' => false] as $status => $expected) {
        $booking->update(['status' => $status]);
        Sanctum::actingAs($tl);
        $data = $this->getJson('/api/v1/team-leader/task')->assertOk()->json('data');
        expect($data['demo_arrival_available'])->toBe($expected, $status);
    }
});

it('never offers the simulator to an ordinary Team Leader or in production', function () {
    [$tl, $booking] = simSeedDemo();
    $booking->update(['status' => 'on_the_way']);
    [$other] = simOtherTeamLeader();

    Sanctum::actingAs($other);
    expect($this->getJson('/api/v1/team-leader/task')->json('data.demo_arrival_available'))->toBeFalse();

    $original = app()['env'];
    app()['env'] = 'production';
    try {
        Sanctum::actingAs($tl);
        expect($this->getJson('/api/v1/team-leader/task')->json('data.demo_arrival_available'))->toBeFalse();
    } finally {
        app()['env'] = $original;
    }
});

it('rerunning the seed command resets a simulated booking to assigned without duplicates', function () {
    [$tl, $booking] = simSeedDemo();
    $booking->update(['status' => 'on_the_way']);
    simSimulate($tl, $booking)->assertOk();
    expect($booking->fresh()->status)->toBe('arrived_pickup');

    Artisan::call('dev:seed-tl-assigned-team-demo');

    expect($booking->fresh()->status)->toBe('assigned')
        ->and(User::where('email', TlDemoFixture::TL_EMAIL)->count())->toBe(1)
        ->and(Booking::where('assigned_team_leader_id', $tl->id)->count())->toBe(1);
});
