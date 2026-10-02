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

// ---------------------------------------------------------------------------
// Production demo gate: TL_DEMO_ARRIVAL_ENABLED (config towmate.demo_arrival_enabled)
// ---------------------------------------------------------------------------

function simAsEnvironment(string $env, ?bool $flag, Closure $fn): void
{
    $originalEnv = app()['env'];
    $originalFlag = config('towmate.demo_arrival_enabled');
    app()['env'] = $env;
    config(['towmate.demo_arrival_enabled' => $flag]);
    try {
        $fn();
    } finally {
        app()['env'] = $originalEnv;
        config(['towmate.demo_arrival_enabled' => $originalFlag]);
    }
}

function simOffered(User $as): bool
{
    Sanctum::actingAs($as);

    return (bool) test()->getJson('/api/v1/team-leader/task')->assertOk()->json('data.demo_arrival_available');
}

it('E: the demo flag defaults to OFF when TL_DEMO_ARRIVAL_ENABLED is absent', function () {
    $repository = \Illuminate\Support\Env::getRepository();
    $previous = \Illuminate\Support\Env::get('TL_DEMO_ARRIVAL_ENABLED');
    $repository->clear('TL_DEMO_ARRIVAL_ENABLED');
    try {
        $config = require config_path('towmate.php');
        expect($config['demo_arrival_enabled'])->toBeFalse();

        foreach (['true' => true, '1' => true, 'false' => false, '0' => false, '' => false, 'garbage' => false] as $raw => $expected) {
            $repository->set('TL_DEMO_ARRIVAL_ENABLED', (string) $raw);
            expect((require config_path('towmate.php'))['demo_arrival_enabled'])->toBe($expected, "raw={$raw}");
        }
    } finally {
        $repository->clear('TL_DEMO_ARRIVAL_ENABLED');
        if ($previous !== null) {
            $repository->set('TL_DEMO_ARRIVAL_ENABLED', (string) $previous);
        }
    }
});

it('A: production with the flag false or unset: 404 and the simulator is not offered', function (?bool $flag) {
    [$tl, $booking] = simSeedDemo();
    $booking->update(['status' => 'on_the_way']);

    simAsEnvironment('production', $flag, function () use ($tl, $booking) {
        simSimulate($tl, $booking)->assertNotFound();
        expect(simOffered($tl))->toBeFalse();
    });

    expect($booking->fresh()->status)->toBe('on_the_way')
        ->and(AuditLog::where('action', 'demo_arrival_simulated')->count())->toBe(0);
})->with([false, null]);

it('B: production with the flag true still blocks any non-demo Team Leader', function () {
    [$demoTl, $demoBooking] = simSeedDemo();
    $demoBooking->update(['status' => 'on_the_way']);
    [$other, $otherBooking] = simOtherTeamLeader();

    simAsEnvironment('production', true, function () use ($other, $otherBooking, $demoBooking) {
        simSimulate($other, $otherBooking)->assertNotFound();
        simSimulate($other, $demoBooking)->assertNotFound();
        expect(simOffered($other))->toBeFalse();
    });

    expect($otherBooking->fresh()->status)->toBe('on_the_way')
        ->and($demoBooking->fresh()->status)->toBe('on_the_way');
});

it('C: production with the flag true still blocks a non-demo booking, even for the demo Team Leader', function () {
    [$tl, $booking] = simSeedDemo();
    $booking->update(['status' => 'on_the_way', 'dispatcher_note' => 'Ordinary customer job']);

    simAsEnvironment('production', true, function () use ($tl, $booking) {
        simSimulate($tl, $booking)->assertNotFound();
        expect(simOffered($tl))->toBeFalse();
    });

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('C: production with the flag true blocks a demo booking whose customer is not the demo customer', function () {
    [$tl, $booking] = simSeedDemo();
    $booking->update(['status' => 'on_the_way']);
    $booking->customer->update(['email' => 'someone.else@example.com']);

    simAsEnvironment('production', true, function () use ($tl, $booking) {
        simSimulate($tl, $booking)->assertNotFound();
        expect(simOffered($tl))->toBeFalse();
    });

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('D: production with the flag true and the real demo fixture: offered, no GPS, both legs', function () {
    [$tl, $booking] = simSeedDemo();
    $booking->update(['status' => 'on_the_way']);
    $unit = Unit::find($booking->assigned_unit_id);
    $before = [
        'pickup' => [$booking->fresh()->pickup_lat, $booking->fresh()->pickup_lng],
        'dropoff' => [$booking->fresh()->dropoff_lat, $booking->fresh()->dropoff_lng],
        'unit' => [$unit?->current_lat, $unit?->current_lng, $unit?->location_updated_at],
    ];

    simAsEnvironment('production', true, function () use ($tl, $booking) {
        expect(simOffered($tl))->toBeTrue();
        simSimulate($tl, $booking)->assertOk()->assertJson(['success' => true, 'status' => 'arrived_pickup']);
        expect($booking->fresh()->status)->toBe('arrived_pickup')
            ->and(simOffered($tl))->toBeFalse(); // not offered at arrived_pickup

        $booking->update(['status' => 'on_job']);
        expect(simOffered($tl))->toBeTrue();
        simSimulate($tl, $booking)->assertOk()->assertJson(['status' => 'arrived_dropoff']);
        expect($booking->fresh()->status)->toBe('arrived_dropoff');
    });

    $fresh = $booking->fresh();
    $unitAfter = Unit::find($booking->assigned_unit_id);
    expect([$fresh->pickup_lat, $fresh->pickup_lng])->toEqual($before['pickup'])
        ->and([$fresh->dropoff_lat, $fresh->dropoff_lng])->toEqual($before['dropoff'])
        ->and([$unitAfter?->current_lat, $unitAfter?->current_lng, $unitAfter?->location_updated_at])->toEqual($before['unit'])
        ->and(AuditLog::where('action', 'demo_arrival_simulated')->count())->toBe(2);
});

it('D: production with the flag true keeps ownership and lifecycle rules for the demo fixture', function () {
    [$tl, $booking] = simSeedDemo();
    [$other] = simOtherTeamLeader();

    simAsEnvironment('production', true, function () use ($tl, $booking, $other) {
        // Wrong lifecycle stage: rejected with zero mutation.
        foreach (['assigned', 'accepted', 'arrived_pickup', 'in_progress', 'arrived_dropoff', 'waiting_verification', 'completed'] as $status) {
            $booking->update(['status' => $status]);
            simSimulate($tl, $booking)->assertStatus(422);
            expect($booking->fresh()->status)->toBe($status);
        }

        // Reassigned away from the demo Team Leader: no longer allowed.
        $booking->update(['status' => 'on_the_way', 'assigned_team_leader_id' => $other->id]);
        simSimulate($tl, $booking)->assertNotFound();
        expect($booking->fresh()->status)->toBe('on_the_way');
    });
});

it('production with the flag true: the normal arrival endpoint still enforces the 150 m rule', function () {
    [$tl, $booking] = simSeedDemo();
    $booking->update(['status' => 'on_the_way']);

    simAsEnvironment('production', true, function () use ($tl, $booking) {
        Sanctum::actingAs($tl);
        test()->patchJson("/api/v1/team-leader/task/{$booking->booking_code}/status", ['status' => 'arrived_pickup'])
            ->assertStatus(422);
        test()->patchJson("/api/v1/team-leader/task/{$booking->booking_code}/status", [
            'status' => 'arrived_pickup', 'lat' => 0.0, 'lng' => 0.0, 'is_demo' => true,
        ])->assertStatus(422);
    });

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('staging stays closed even with the flag true', function () {
    [$tl, $booking] = simSeedDemo();
    $booking->update(['status' => 'on_the_way']);

    simAsEnvironment('staging', true, function () use ($tl, $booking) {
        simSimulate($tl, $booking)->assertNotFound();
        expect(simOffered($tl))->toBeFalse();
    });
});
