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
// Production presentation gate:
//   TL_DEMO_ARRIVAL_ENABLED  (config towmate.demo_arrival_enabled)
//   TL_DEMO_TEAM_LEADER_EMAIL (config towmate.demo_team_leader_email)
// In production the demo/fixture customer + note checks are NOT used: any booking
// actually assigned to the ONE configured Team Leader qualifies.
// ---------------------------------------------------------------------------

const SIM_PRESENTER_EMAIL = 'presenter.tl@example.com';

function simAsProduction(?bool $flag, ?string $email, Closure $fn, string $env = 'production'): void
{
    $originalEnv = app()['env'];
    $originalFlag = config('towmate.demo_arrival_enabled');
    $originalEmail = config('towmate.demo_team_leader_email');
    app()['env'] = $env;
    config(['towmate.demo_arrival_enabled' => $flag, 'towmate.demo_team_leader_email' => $email]);
    try {
        $fn();
    } finally {
        app()['env'] = $originalEnv;
        config(['towmate.demo_arrival_enabled' => $originalFlag, 'towmate.demo_team_leader_email' => $originalEmail]);
    }
}

function simOffered(User $as): bool
{
    Sanctum::actingAs($as);

    return (bool) test()->getJson('/api/v1/team-leader/task')->assertOk()->json('data.demo_arrival_available');
}

/** A real, ordinary Team Leader + ordinary booking (no demo customer, no DEMO note). */
function simPresenter(string $status = 'on_the_way'): array
{
    [$tl, $booking] = simOtherTeamLeader();
    $tl->update(['email' => SIM_PRESENTER_EMAIL]);
    $booking->update(['status' => $status]);

    return [$tl->fresh(), $booking->fresh()];
}

function simSnapshot(Booking $booking): array
{
    $b = $booking->fresh();
    $unit = Unit::find($b->assigned_unit_id);

    return [
        'pickup' => [$b->pickup_lat, $b->pickup_lng],
        'dropoff' => [$b->dropoff_lat, $b->dropoff_lng],
        'unit' => [$unit?->current_lat, $unit?->current_lng, $unit?->location_updated_at],
    ];
}

it('E: the demo flag and the presentation email both default to OFF/unset', function () {
    $repository = \Illuminate\Support\Env::getRepository();
    $previous = [
        'flag' => \Illuminate\Support\Env::get('TL_DEMO_ARRIVAL_ENABLED'),
        'email' => \Illuminate\Support\Env::get('TL_DEMO_TEAM_LEADER_EMAIL'),
    ];
    $repository->clear('TL_DEMO_ARRIVAL_ENABLED');
    $repository->clear('TL_DEMO_TEAM_LEADER_EMAIL');
    try {
        $config = require config_path('towmate.php');
        expect($config['demo_arrival_enabled'])->toBeFalse()
            ->and($config['demo_team_leader_email'])->toBeNull();

        foreach (['true' => true, '1' => true, 'false' => false, '0' => false, '' => false, 'garbage' => false] as $raw => $expected) {
            $repository->set('TL_DEMO_ARRIVAL_ENABLED', (string) $raw);
            expect((require config_path('towmate.php'))['demo_arrival_enabled'])->toBe($expected, "raw={$raw}");
        }

        foreach (['' => null, '   ' => null, '  Presenter.TL@Example.com ' => 'presenter.tl@example.com'] as $raw => $expected) {
            $repository->set('TL_DEMO_TEAM_LEADER_EMAIL', (string) $raw);
            expect((require config_path('towmate.php'))['demo_team_leader_email'])->toBe($expected, "email raw=[{$raw}]");
        }
    } finally {
        $repository->clear('TL_DEMO_ARRIVAL_ENABLED');
        $repository->clear('TL_DEMO_TEAM_LEADER_EMAIL');
        if ($previous['flag'] !== null) {
            $repository->set('TL_DEMO_ARRIVAL_ENABLED', (string) $previous['flag']);
        }
        if ($previous['email'] !== null) {
            $repository->set('TL_DEMO_TEAM_LEADER_EMAIL', (string) $previous['email']);
        }
    }
});

it('A: production with the flag false or unset: 404 and not offered, even for the configured Team Leader', function (?bool $flag) {
    [$tl, $booking] = simPresenter();

    simAsProduction($flag, SIM_PRESENTER_EMAIL, function () use ($tl, $booking) {
        simSimulate($tl, $booking)->assertNotFound();
        expect(simOffered($tl))->toBeFalse();
    });

    expect($booking->fresh()->status)->toBe('on_the_way')
        ->and(AuditLog::where('action', 'demo_arrival_simulated')->count())->toBe(0);
})->with([false, null]);

it('B: production with the flag true and no configured email: nobody can use it', function (?string $email) {
    [$presenter, $presenterBooking] = simPresenter();
    [$demoTl, $demoBooking] = simSeedDemo();
    $demoBooking->update(['status' => 'on_the_way']);

    simAsProduction(true, $email, function () use ($presenter, $presenterBooking, $demoTl, $demoBooking) {
        simSimulate($presenter, $presenterBooking)->assertNotFound();
        simSimulate($demoTl, $demoBooking)->assertNotFound();
        expect(simOffered($presenter))->toBeFalse()->and(simOffered($demoTl))->toBeFalse();
    });

    expect($presenterBooking->fresh()->status)->toBe('on_the_way')
        ->and($demoBooking->fresh()->status)->toBe('on_the_way');
})->with([null, '']);

it('B: production with the flag true blocks every Team Leader except the configured one', function () {
    [$presenter, $presenterBooking] = simPresenter();
    [$other, $otherBooking] = simOtherTeamLeader();
    [$fixtureTl, $fixtureBooking] = simSeedDemo();
    $fixtureBooking->update(['status' => 'on_the_way']);

    simAsProduction(true, SIM_PRESENTER_EMAIL, function () use ($other, $otherBooking, $fixtureTl, $fixtureBooking) {
        // An ordinary Team Leader, even on their own booking.
        simSimulate($other, $otherBooking)->assertNotFound();
        expect(simOffered($other))->toBeFalse();
        // The old seeded demo Team Leader no longer gets production access.
        simSimulate($fixtureTl, $fixtureBooking)->assertNotFound();
        expect(simOffered($fixtureTl))->toBeFalse();
    });

    expect($otherBooking->fresh()->status)->toBe('on_the_way')
        ->and($fixtureBooking->fresh()->status)->toBe('on_the_way')
        ->and($presenterBooking->fresh()->status)->toBe('on_the_way');
});

it('B: a non-Team-Leader account with the configured email is not a demo user', function () {
    $role = Role::find(2) ?? tap(new Role(['name' => 'Dispatcher', 'description' => 'Dispatcher']), function ($r) {
        $r->id = 2;
        $r->save();
    });
    $impostor = User::factory()->create(['role_id' => $role->id, 'email' => SIM_PRESENTER_EMAIL]);

    simAsProduction(true, SIM_PRESENTER_EMAIL, function () use ($impostor) {
        expect(TlDemoFixture::isDemoUser($impostor))->toBeFalse();
    });
});

it('C: production, configured Team Leader, but the booking belongs to another Team Leader: 404, no change', function () {
    [$presenter] = simPresenter();
    [, $foreignBooking] = simOtherTeamLeader();

    simAsProduction(true, SIM_PRESENTER_EMAIL, function () use ($presenter, $foreignBooking) {
        simSimulate($presenter, $foreignBooking)->assertNotFound();
    });

    expect($foreignBooking->fresh()->status)->toBe('on_the_way')
        ->and(AuditLog::where('action', 'demo_arrival_simulated')->count())->toBe(0);
});

it('D: configured Team Leader + own ordinary on_the_way booking: simulated arrival at pickup, no GPS written', function () {
    [$tl, $booking] = simPresenter('on_the_way');
    $before = simSnapshot($booking);

    simAsProduction(true, SIM_PRESENTER_EMAIL, function () use ($tl, $booking) {
        expect(simOffered($tl))->toBeTrue();
        simSimulate($tl, $booking)->assertOk()->assertJson(['success' => true, 'status' => 'arrived_pickup']);
        expect($booking->fresh()->status)->toBe('arrived_pickup')
            ->and(simOffered($tl))->toBeFalse();
    });

    expect(simSnapshot($booking))->toEqual($before)
        ->and(AuditLog::where('action', 'demo_arrival_simulated')->where('entity_id', $booking->id)->count())->toBe(1);
});

it('D: configured Team Leader + own ordinary on_job booking: simulated arrival at drop-off, no GPS written', function () {
    [$tl, $booking] = simPresenter('on_job');
    $before = simSnapshot($booking);

    simAsProduction(true, SIM_PRESENTER_EMAIL, function () use ($tl, $booking) {
        expect(simOffered($tl))->toBeTrue();
        simSimulate($tl, $booking)->assertOk()->assertJson(['status' => 'arrived_dropoff']);
        expect($booking->fresh()->status)->toBe('arrived_dropoff');
    });

    expect(simSnapshot($booking))->toEqual($before);
});

it('D: the configured email matches case-insensitively and ignores client-supplied targets', function () {
    [$tl, $booking] = simPresenter('on_the_way');

    simAsProduction(true, SIM_PRESENTER_EMAIL, function () use ($tl, $booking) {
        simSimulate($tl, $booking, ['status' => 'completed', 'target' => 'completed', 'skip_location' => true])
            ->assertOk()->assertJson(['status' => 'arrived_pickup']);
    });

    expect($booking->fresh()->status)->toBe('arrived_pickup');
});

it('D: lifecycle rules still apply to the configured Team Leader', function () {
    [$tl, $booking] = simPresenter();

    simAsProduction(true, SIM_PRESENTER_EMAIL, function () use ($tl, $booking) {
        foreach (['assigned', 'accepted', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'arrived_dropoff', 'waiting_verification', 'completed', 'returned'] as $status) {
            $booking->update(['status' => $status]);
            simSimulate($tl, $booking)->assertStatus(422);
            expect($booking->fresh()->status)->toBe($status);
        }

        foreach (['assigned' => false, 'accepted' => true, 'on_the_way' => true, 'arrived_pickup' => false, 'on_job' => true, 'arrived_dropoff' => false] as $status => $expected) {
            $booking->update(['status' => $status]);
            expect(simOffered($tl))->toBe($expected, $status);
        }
    });
});

it('D: if the booking is reassigned away from the configured Team Leader the simulator stops working', function () {
    [$tl, $booking] = simPresenter();
    [$other] = simOtherTeamLeader();
    $booking->update(['assigned_team_leader_id' => $other->id]);

    simAsProduction(true, SIM_PRESENTER_EMAIL, function () use ($tl, $booking) {
        simSimulate($tl, $booking)->assertNotFound();
    });

    expect($booking->fresh()->status)->toBe('on_the_way');
});

it('production demo mode requires authentication', function () {
    [, $booking] = simPresenter();

    simAsProduction(true, SIM_PRESENTER_EMAIL, function () use ($booking) {
        test()->postJson("/api/v1/team-leader/demo/task/{$booking->booking_code}/simulate-arrival")->assertUnauthorized();
    });
});

it('production demo mode: the normal arrival endpoint still enforces the 150 m rule', function () {
    [$tl, $booking] = simPresenter('on_the_way');

    simAsProduction(true, SIM_PRESENTER_EMAIL, function () use ($tl, $booking) {
        Sanctum::actingAs($tl);
        $url = "/api/v1/team-leader/task/{$booking->booking_code}/status";

        test()->patchJson($url, ['status' => 'arrived_pickup'])->assertStatus(422);
        test()->patchJson($url, ['status' => 'arrived_pickup', 'lat' => 0.0, 'lng' => 0.0, 'is_demo' => true])->assertStatus(422);
        expect($booking->fresh()->status)->toBe('on_the_way');

        // Genuinely at the pickup point: the real endpoint still works.
        test()->patchJson($url, ['status' => 'arrived_pickup', 'lat' => 14.6760, 'lng' => 121.0437])->assertOk();
        expect($booking->fresh()->status)->toBe('arrived_pickup');
    });
});

it('staging stays closed even with the flag and email configured', function () {
    [$tl, $booking] = simPresenter();

    simAsProduction(true, SIM_PRESENTER_EMAIL, function () use ($tl, $booking) {
        simSimulate($tl, $booking)->assertNotFound();
        expect(simOffered($tl))->toBeFalse();
    }, 'staging');
});
