<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

it('loads the dispatcher dashboard for a dispatcher user', function () {
    Role::query()->firstOrCreate(['name' => 'Super Admin']);
    Role::query()->firstOrCreate(['name' => 'Dispatcher']);

    $dispatcher = User::factory()->create([
        'role_id' => 2,
    ]);

    $this->actingAs($dispatcher)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertDontSee('Busy Team Leaders')
        ->assertDontSee('Online Team Leaders')
        ->assertDontSee('Delayed Jobs')
        ->assertDontSee('Team leader sync');
});

it('syncs team leader online and workload states to the dispatcher live overview', function () {
    Role::query()->firstOrCreate(['name' => 'Super Admin']);
    Role::query()->firstOrCreate(['name' => 'Dispatcher']);
    Role::query()->firstOrCreate(['name' => 'Team Leader']);

    $dispatcher = User::factory()->create([
        'role_id' => 2,
    ]);

    $teamLeader = User::factory()->create([
        'role_id' => 3,
        'name' => 'TL Santos',
    ]);

    Unit::create([
        'name' => 'Unit Santos',
        'plate_number' => 'SAN-1001',
        'truck_type_id' => TruckType::create(['name' => 'Overview Truck', 'base_rate' => 1500, 'per_km_rate' => 75, 'max_tonnage' => 5, 'description' => 'Live overview'])->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
    ]);

    Cache::put("teamleader:presence:{$teamLeader->id}", now()->timestamp, now()->addMinutes(2));

    $this->actingAs($dispatcher)
        ->getJson(route('admin.live-overview'))
        ->assertOk()
        ->assertJsonPath('onlineTeamLeadersCount', 1)
        ->assertJsonPath('offlineTeamLeadersCount', 0)
        ->assertJsonPath('teamLeaderStatuses.0.name', 'TL Santos')
        ->assertJsonPath('teamLeaderStatuses.0.presence', 'online')
        ->assertJsonPath('teamLeaderStatuses.0.workload', 'available');
});

it('removes the legacy member drivers stat card from the available units module', function () {
    Role::query()->firstOrCreate(['name' => 'Super Admin']);
    Role::query()->firstOrCreate(['name' => 'Dispatcher']);

    $dispatcher = User::factory()->create([
        'role_id' => 2,
    ]);

    $this->actingAs($dispatcher)
        ->get(route('admin.available-units'))
        ->assertOk()
        ->assertDontSee('Member Drivers');
});

it('no longer serves the retired available-units toggle URL', function () {
    foreach (['Super Admin', 'Dispatcher', 'Team Leader'] as $roleName) {
        Role::query()->firstOrCreate(['name' => $roleName]);
    }

    $dispatcher = User::factory()->create(['role_id' => 2]);
    $truckType = TruckType::create(['name' => 'Toggle Truck', 'base_rate' => 1500, 'per_km_rate' => 75, 'max_tonnage' => 5, 'description' => 'Toggle visibility test']);
    $unit = Unit::create(['name' => 'Unit Toggle 1', 'plate_number' => 'TGL-1001', 'truck_type_id' => $truckType->id, 'status' => 'available']);

    $this->actingAs($dispatcher)->patch("/admin-dashboard/available-units/{$unit->id}/toggle")->assertNotFound();

    expect($unit->fresh()->status)->toBe('available');

    $this->actingAs($dispatcher)->get(route('admin.available-units'))->assertOk()->assertSee('Unit Toggle 1');
});

it('shows the assigned unit and saved driver in the dispatcher team leaders dashboard', function () {
    Role::query()->firstOrCreate(['name' => 'Super Admin']);
    Role::query()->firstOrCreate(['name' => 'Dispatcher']);
    Role::query()->firstOrCreate(['name' => 'Team Leader']);

    $dispatcher = User::factory()->create([
        'role_id' => 2,
    ]);

    $teamLeader = User::factory()->create([
        'role_id' => 3,
        'name' => 'TL Ramos',
    ]);

    $truckType = TruckType::create([
        'name' => 'Flatbed Unit',
        'base_rate' => 1600,
        'per_km_rate' => 80,
        'max_tonnage' => 5,
        'description' => 'Dispatcher visibility test',
    ]);

    $unit = Unit::create([
        'name' => 'Unit 21',
        'plate_number' => 'ABC-1234',
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'driver_name' => 'Mario Dela Cruz',
        'status' => 'available',
    ]);

    $customer = Customer::create([
        'full_name' => 'Jose Cruz',
        'age' => 36,
        'phone' => '09179998888',
        'email' => 'jose@example.com',
    ]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'created_by_admin_id' => $dispatcher->id,
        'age' => 36,
        'pickup_address' => 'Makati Avenue',
        'dropoff_address' => 'BGC',
        'distance_km' => 6,
        'base_rate' => 1600,
        'per_km_rate' => 80,
        'computed_total' => 2080,
        'final_total' => 2080,
        'quotation_generated' => true,
        'quotation_number' => 'Q-DISP-2001',
        'status' => 'assigned',
        'assigned_team_leader_id' => $teamLeader->id,
        'assigned_at' => now(),
        'driver_name' => 'Mario Dela Cruz',
    ]);

    Cache::put("teamleader:presence:{$teamLeader->id}", now()->timestamp, now()->addMinutes(2));

    $this->actingAs($dispatcher)
        ->get(route('admin.drivers'))
        ->assertOk()
        ->assertSee('TL Ramos')
        ->assertSee('Unit 21')
        ->assertSee('Mario Dela Cruz');
});

it('shows returned tasks in the dispatcher queue with the return reason', function () {
    Role::query()->firstOrCreate(['name' => 'Super Admin']);
    Role::query()->firstOrCreate(['name' => 'Dispatcher']);
    Role::query()->firstOrCreate(['name' => 'Team Leader']);

    $dispatcher = User::factory()->create([
        'role_id' => 2,
    ]);

    $teamLeader = User::factory()->create([
        'role_id' => 3,
        'name' => 'TL Returner',
    ]);

    $truckType = TruckType::create([
        'name' => 'Recovery Unit',
        'base_rate' => 1600,
        'per_km_rate' => 80,
        'max_tonnage' => 5,
        'description' => 'Returned task visibility',
    ]);

    $unit = Unit::create([
        'name' => 'Unit 99',
        'plate_number' => 'RET-0099',
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
    ]);

    $customer = Customer::create([
        'full_name' => 'Ana Return',
        'age' => 30,
        'phone' => '09170000000',
        'email' => 'ana@return.test',
    ]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'created_by_admin_id' => $dispatcher->id,
        'age' => 30,
        'pickup_address' => 'Cubao',
        'dropoff_address' => 'Pasay',
        'distance_km' => 10,
        'base_rate' => 1600,
        'per_km_rate' => 80,
        'computed_total' => 2400,
        'final_total' => 2400,
        'quotation_generated' => true,
        'quotation_number' => 'Q-RETURN-1',
        'status' => 'assigned',
        'returned_at' => now(),
        'return_reason' => 'Vehicle issue during pickup.',
        'returned_by_team_leader_id' => $teamLeader->id,
    ]);

    $this->actingAs($dispatcher)
        ->get(route('admin.dispatch'))
        ->assertOk()
        ->assertSee('Returned')
        ->assertSee('Vehicle issue during pickup.')
        ->assertSee($booking->job_code);
});

it('lets the dispatcher assign a unit to a team leader from the team leaders module', function () {
    foreach (['Super Admin', 'Dispatcher', 'Team Leader'] as $roleName) {
        Role::query()->firstOrCreate(['name' => $roleName]);
    }

    $dispatcher = User::factory()->create([
        'role_id' => 2,
    ]);

    $teamLeader = User::factory()->create([
        'role_id' => 3,
        'name' => 'TL Navarro',
    ]);

    $truckType = TruckType::create([
        'name' => 'Rescue Unit',
        'base_rate' => 1700,
        'per_km_rate' => 85,
        'max_tonnage' => 5,
        'description' => 'Daily dispatch assignment',
    ]);

    $unit = Unit::create([
        'name' => 'Unit 45',
        'plate_number' => 'TL-4501',
        'truck_type_id' => $truckType->id,
        'status' => 'available',
    ]);

    Cache::put("teamleader:presence:{$teamLeader->id}", now()->timestamp, now()->addMinutes(2));

    $this->actingAs($dispatcher)
        ->post(route('admin.drivers.units.assign-team-leader', $unit), [
            'team_leader_id' => $teamLeader->id,
        ])
        ->assertRedirect(route('admin.drivers'))
        ->assertSessionHasNoErrors();

    $unit->refresh();

    expect($unit->team_leader_id)->toBe($teamLeader->id);

    $this->actingAs($dispatcher)
        ->get(route('admin.drivers'))
        ->assertOk()
        ->assertSee('TL Navarro')
        ->assertSee('Unit 45');
});

it('shows online leaders first but falls back to view all when everyone is offline', function () {
    Cache::flush();

    foreach (['Super Admin', 'Dispatcher', 'Team Leader'] as $roleName) {
        Role::query()->firstOrCreate(['name' => $roleName]);
    }

    $dispatcher = User::factory()->create(['role_id' => 2]);
    $truckType = TruckType::create(['name' => 'Presence Truck', 'base_rate' => 1500, 'per_km_rate' => 75, 'max_tonnage' => 5, 'description' => 'Presence filter']);

    $offlineLeader = User::factory()->create(['role_id' => 3, 'name' => 'TL Offline']);
    Unit::create(['name' => 'Unit Offline', 'plate_number' => 'OFF-1001', 'truck_type_id' => $truckType->id, 'team_leader_id' => $offlineLeader->id, 'status' => 'available']);

    // Everyone offline: the list still shows every unit (nothing is hidden), the
    // Presence filter offers Online/Offline/All, and the leader is marked Offline.
    $this->actingAs($dispatcher)
        ->get(route('admin.drivers'))
        ->assertOk()
        ->assertSee('Presence')
        ->assertSee('<option value="all">All</option>', false)
        ->assertSee('<option value="online">Online</option>', false)
        ->assertSee('<option value="offline">Offline</option>', false)
        ->assertSee('Unit Offline')
        ->assertSee('TL Offline')
        ->assertSee('data-presence="offline"', false)
        ->assertDontSee('data-presence="online"', false);

    $onlineLeader = User::factory()->create(['role_id' => 3, 'name' => 'TL Online']);
    Unit::create(['name' => 'Unit Online', 'plate_number' => 'ONL-1001', 'truck_type_id' => $truckType->id, 'team_leader_id' => $onlineLeader->id, 'status' => 'available']);
    Cache::put("teamleader:presence:{$onlineLeader->id}", now()->timestamp, now()->addMinutes(2));

    $this->actingAs($dispatcher)
        ->get(route('admin.drivers'))
        ->assertOk()
        ->assertSee('data-presence="online"', false)
        ->assertSee('data-presence="offline"', false)
        ->assertSee('Unit Online');
});
it('shows an active duty queue with ready deployed and unavailable crew states', function () {
    Cache::flush();

    foreach (['Super Admin', 'Dispatcher', 'Team Leader'] as $roleName) {
        Role::query()->firstOrCreate(['name' => $roleName]);
    }

    $dispatcher = User::factory()->create(['role_id' => 2]);
    $readyLeader = User::factory()->create(['role_id' => 3, 'name' => 'TL Ready Crew']);
    $busyLeader = User::factory()->create(['role_id' => 3, 'name' => 'TL Busy Crew']);
    $offlineLeader = User::factory()->create(['role_id' => 3, 'name' => 'TL Offline Crew']);

    Cache::put("teamleader:presence:{$readyLeader->id}", now()->timestamp, now()->addMinutes(2));
    Cache::put("teamleader:presence:{$busyLeader->id}", now()->timestamp, now()->addMinutes(2));

    $truckType = TruckType::create(['name' => 'Queue Visibility Truck', 'base_rate' => 1600, 'per_km_rate' => 80, 'max_tonnage' => 5, 'description' => 'Queue grouping visibility']);

    Unit::create(['name' => 'Unit Ready 10', 'plate_number' => 'RDY-1010', 'truck_type_id' => $truckType->id, 'team_leader_id' => $readyLeader->id, 'driver_name' => 'Ready Driver', 'status' => 'available']);
    $busyUnit = Unit::create(['name' => 'Unit Busy 11', 'plate_number' => 'BUS-1111', 'truck_type_id' => $truckType->id, 'team_leader_id' => $busyLeader->id, 'status' => 'on_job']);
    Unit::create(['name' => 'Unit Offline 12', 'plate_number' => 'OFF-1212', 'truck_type_id' => $truckType->id, 'team_leader_id' => $offlineLeader->id, 'status' => 'available']);

    Booking::create([
        'customer_id' => Customer::create(['full_name' => 'Queue Test Customer', 'phone' => '09178889999', 'email' => 'queue@test.com'])->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $busyUnit->id,
        'pickup_address' => 'Makati',
        'dropoff_address' => 'Pasig',
        'distance_km' => 8,
        'base_rate' => 1600,
        'per_km_rate' => 80,
        'computed_total' => 2240,
        'final_total' => 2240,
        'status' => 'assigned',
        'assigned_team_leader_id' => $busyLeader->id,
    ]);

    $html = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->getContent();

    // Ready crew, a crew on an active job, and the offline crew's truck each show their own state.
    expect($html)->toContain('ul-status ul-status--available')
        ->toContain('ul-status ul-status--busy')
        ->toContain('data-presence="online"')
        ->toContain('data-presence="offline"');
    expect($html)->toMatch('/Unit Busy 11.*?ul-status--busy/s');
});

it('allows assigning a unit of the same truck type to the same team leader', function () {
    Cache::flush();

    foreach (['Super Admin', 'Dispatcher', 'Team Leader'] as $roleName) {
        Role::query()->firstOrCreate(['name' => $roleName]);
    }

    $dispatcher = User::factory()->create([
        'role_id' => 2,
    ]);

    $teamLeader = User::factory()->create([
        'role_id' => 3,
        'name' => 'TL Coverage Lock',
    ]);

    User::factory()->create([
        'role_id' => 3,
        'name' => 'TL Spare Coverage',
    ]);

    Cache::put("teamleader:presence:{$teamLeader->id}", now()->timestamp, now()->addMinutes(2));

    $truckType = TruckType::create([
        'name' => 'Coverage Truck',
        'base_rate' => 1600,
        'per_km_rate' => 80,
        'max_tonnage' => 5,
        'description' => 'Coverage balancing support',
    ]);

    $currentUnit = Unit::create([
        'name' => 'Coverage Unit 1',
        'plate_number' => 'CVR-1001',
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
    ]);

    $sameTruckTypeUnit = Unit::create([
        'name' => 'Coverage Unit 2',
        'plate_number' => 'CVR-1002',
        'truck_type_id' => $truckType->id,
        'status' => 'available',
    ]);

    $this->actingAs($dispatcher)
        ->post(route('admin.drivers.units.assign-team-leader', $sameTruckTypeUnit), [
            'team_leader_id' => $teamLeader->id,
        ])
        ->assertRedirect(route('admin.drivers'))
        ->assertSessionHasNoErrors();

    // The new unit is now assigned; old unit loses its team_leader_id.
    expect($sameTruckTypeUnit->fresh()->team_leader_id)->toBe($teamLeader->id)
        ->and($currentUnit->fresh()->team_leader_id)->toBeNull();

    $this->actingAs($dispatcher)
        ->get(route('admin.drivers'))
        ->assertOk()
        ->assertDontSee('assign this truck type to another team leader');
});

it('prevents assigning a unit that is already owned by a different team leader', function () {
    Cache::flush();

    foreach (['Super Admin', 'Dispatcher', 'Team Leader'] as $roleName) {
        Role::query()->firstOrCreate(['name' => $roleName]);
    }

    $dispatcher = User::factory()->create(['role_id' => 2]);

    $ownerLeader = User::factory()->create(['role_id' => 3, 'name' => 'TL Owner']);
    $requestingLeader = User::factory()->create(['role_id' => 3, 'name' => 'TL Requester']);

    Cache::put("teamleader:presence:{$requestingLeader->id}", now()->timestamp, now()->addMinutes(2));

    $truckType = TruckType::create([
        'name' => 'Ownership Lock Truck',
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'max_tonnage' => 5,
        'description' => 'Ownership guard test',
    ]);

    $ownedUnit = Unit::create([
        'name' => 'Owned Unit',
        'plate_number' => 'OWN-0001',
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $ownerLeader->id,
        'status' => 'available',
    ]);

    $this->actingAs($dispatcher)
        ->post(route('admin.drivers.units.assign-team-leader', $ownedUnit), [
            'team_leader_id' => $requestingLeader->id,
        ])
        ->assertSessionHasErrors(['team']);

    expect($ownedUnit->fresh()->team_leader_id)->toBe($ownerLeader->id);
    expect($requestingLeader->fresh()->unit)->toBeNull();
});

it('does not show the redundant available unit status badge on team leader cards', function () {
    Cache::flush();

    Role::query()->firstOrCreate(['name' => 'Super Admin']);
    Role::query()->firstOrCreate(['name' => 'Dispatcher']);
    Role::query()->firstOrCreate(['name' => 'Team Leader']);

    $dispatcher = User::factory()->create(['role_id' => 2]);
    $teamLeader = User::factory()->create(['role_id' => 3, 'name' => 'TL Badge Test']);

    Cache::put("teamleader:presence:{$teamLeader->id}", now()->timestamp, now()->addMinutes(2));

    $truckType = TruckType::create([
        'name' => 'Badge Truck',
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'max_tonnage' => 5,
        'description' => 'Badge removal test',
    ]);

    Unit::create([
        'name' => 'Badge Unit',
        'plate_number' => 'BDG-0001',
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
    ]);

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.drivers'))
        ->assertOk()
        ->getContent();

    // The Available unit status label should NOT appear as a separate badge in the status stack
    $this->assertStringNotContainsString('status-available">
                                    Available', $html);
});

it('no longer lets the retired status endpoint change a team leader or unit status', function () {
    Cache::flush();

    foreach (['Super Admin', 'Dispatcher', 'Team Leader'] as $roleName) {
        Role::query()->firstOrCreate(['name' => $roleName]);
    }

    $dispatcher = User::factory()->create(['role_id' => 2]);
    $teamLeader = User::factory()->create(['role_id' => 3, 'name' => 'TL Status Override']);

    $truckType = TruckType::create([
        'name' => 'Override Truck',
        'base_rate' => 1600,
        'per_km_rate' => 80,
        'max_tonnage' => 5,
        'description' => 'Dispatcher override support',
    ]);

    $unit = Unit::create([
        'name' => 'Unit Override 7',
        'plate_number' => 'OVR-7007',
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'maintenance',
    ]);

    // The URL is a hard-coded literal: the route name no longer exists.
    $this->actingAs($dispatcher)
        ->post("/admin-dashboard/drivers/{$teamLeader->id}/update-status", [
            'operational_status' => 'busy',
            'unit_status' => 'available',
            'status_reason' => 'Assigned to active recovery job.',
        ])
        ->assertNotFound();

    expect($unit->fresh()->status)->toBe('maintenance');
    expect(Cache::get("teamleader:dispatcher-status:{$teamLeader->id}"))->toBeNull();
});
it('shows a zone-based recommendation for dispatch instead of relying on raw gps proximity', function () {
    Cache::flush();

    Role::query()->firstOrCreate(['name' => 'Super Admin']);
    Role::query()->firstOrCreate(['name' => 'Dispatcher']);
    Role::query()->firstOrCreate(['name' => 'Team Leader']);

    $dispatcher = User::factory()->create([
        'role_id' => 2,
    ]);

    $northLeader = User::factory()->create([
        'role_id' => 3,
        'name' => 'TL North Zone',
    ]);

    $makatiLeader = User::factory()->create([
        'role_id' => 3,
        'name' => 'TL Makati Zone',
    ]);

    Cache::put("teamleader:presence:{$northLeader->id}", now()->timestamp, now()->addMinutes(2));
    Cache::put("teamleader:presence:{$makatiLeader->id}", now()->timestamp, now()->addMinutes(2));

    $truckType = TruckType::create([
        'name' => 'Zone Dispatch Truck',
        'base_rate' => 1700,
        'per_km_rate' => 85,
        'max_tonnage' => 5,
        'description' => 'Zone suggestion support',
    ]);

    $northUnit = Unit::create([
        'name' => 'North Unit 2',
        'plate_number' => 'NOR-2002',
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $northLeader->id,
        'driver_name' => 'North Driver',
        'status' => 'available',
    ]);

    $makatiUnit = Unit::create([
        'name' => 'Makati Unit 7',
        'plate_number' => 'MKT-7007',
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $makatiLeader->id,
        'driver_name' => 'Makati Driver',
        'status' => 'available',
    ]);

    $customer = Customer::create([
        'full_name' => 'Zone Test Customer',
        'phone' => '09175556666',
        'email' => 'zone@test.com',
    ]);

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $makatiUnit->id,
        'assigned_team_leader_id' => $makatiLeader->id,
        'pickup_address' => 'Ayala Avenue, Makati City',
        'dropoff_address' => 'Legazpi Village, Makati City',
        'distance_km' => 5,
        'base_rate' => 1700,
        'per_km_rate' => 85,
        'computed_total' => 2125,
        'final_total' => 2125,
        'status' => 'completed',
        'completed_at' => now()->subDay(),
    ]);

    $incomingBooking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Paseo de Roxas, Makati City',
        'dropoff_address' => 'BGC, Taguig City',
        'distance_km' => 8,
        'base_rate' => 1700,
        'per_km_rate' => 85,
        'computed_total' => 2380,
        'final_total' => 2380,
        'status' => 'requested',
    ]);

    $html = $this->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk()->getContent();

    // The Book Now card carries the inferred zone and the recommended unit as data attributes.
    // Plain string checks keep a failure cheap: assertSee would diff the whole ~300 KB page.
    $has = fn (string $needle) => str_contains($html, $needle);
    expect($has((string) $incomingBooking->job_code))->toBeTrue('the incoming booking is listed')
        ->and($has('data-dispatch-zone="Makati City"'))->toBeTrue('zone inferred from the pickup address')
        ->and($has('data-recommended-unit="' . $makatiUnit->id . '"'))->toBeTrue('the Makati unit is recommended from its zone history')
        ->and($has('data-recommended-unit="' . $northUnit->id . '"'))->toBeFalse('the North unit is not recommended');
});

it('redirects logout to the login page on the configured local app url', function () {
    config()->set('app.url', 'http://127.0.0.1:8000');

    Role::query()->firstOrCreate(['name' => 'Team Leader']);

    $teamLeader = User::factory()->create([
        'role_id' => 1,
    ]);

    $this->actingAs($teamLeader)
        ->withServerVariables(['HTTP_HOST' => '127.0.0.1:8001'])
        ->post(route('logout'))
        ->assertRedirect('http://127.0.0.1:8000/login');
});
