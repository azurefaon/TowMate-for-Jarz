<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

it('loads the dispatcher dashboard for a dispatcher user', function () {
    Role::query()->create(['name' => 'Super Admin']);
    Role::query()->create(['name' => 'Dispatcher']);

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
    Role::query()->create(['name' => 'Super Admin']);
    Role::query()->create(['name' => 'Dispatcher']);
    Role::query()->create(['name' => 'Team Leader']);

    $dispatcher = User::factory()->create([
        'role_id' => 2,
    ]);

    $teamLeader = User::factory()->create([
        'role_id' => 3,
        'name' => 'TL Santos',
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
    Role::query()->create(['name' => 'Super Admin']);
    Role::query()->create(['name' => 'Dispatcher']);

    $dispatcher = User::factory()->create([
        'role_id' => 2,
    ]);

    $this->actingAs($dispatcher)
        ->get(route('admin.available-units'))
        ->assertOk()
        ->assertDontSee('Member Drivers');
});

it('lets the dispatcher toggle a unit between available and not available', function () {
    Role::query()->create(['name' => 'Super Admin']);
    Role::query()->create(['name' => 'Dispatcher']);

    $dispatcher = User::factory()->create([
        'role_id' => 2,
    ]);

    $truckType = TruckType::create([
        'name' => 'Toggle Truck',
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'max_tonnage' => 5,
        'description' => 'Toggle visibility test',
    ]);

    $unit = Unit::create([
        'name' => 'Unit Toggle 1',
        'plate_number' => 'TGL-1001',
        'truck_type_id' => $truckType->id,
        'status' => 'available',
    ]);

    $this->actingAs($dispatcher)
        ->patch(route('admin.available-units.toggle', $unit))
        ->assertRedirect(route('admin.available-units'));

    expect($unit->fresh()->status)->toBe('maintenance');

    $this->actingAs($dispatcher)
        ->get(route('admin.available-units'))
        ->assertOk()
        ->assertSee('Unit Toggle 1')
        ->assertSee('Not Available');

    $this->actingAs($dispatcher)
        ->patch(route('admin.available-units.toggle', $unit))
        ->assertRedirect(route('admin.available-units'));

    expect($unit->fresh()->status)->toBe('available');
});

it('shows the assigned unit and saved driver in the dispatcher team leaders dashboard', function () {
    Role::query()->create(['name' => 'Super Admin']);
    Role::query()->create(['name' => 'Dispatcher']);
    Role::query()->create(['name' => 'Team Leader']);

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
    Role::query()->create(['name' => 'Super Admin']);
    Role::query()->create(['name' => 'Dispatcher']);
    Role::query()->create(['name' => 'Team Leader']);

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
    Role::query()->create(['name' => 'Super Admin']);
    Role::query()->create(['name' => 'Dispatcher']);
    Role::query()->create(['name' => 'Team Leader']);

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
        ->post(route('admin.drivers.assign-unit', $teamLeader), [
            'unit_id' => $unit->id,
        ])
        ->assertRedirect(route('admin.drivers'));

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

    Role::query()->create(['name' => 'Super Admin']);
    Role::query()->create(['name' => 'Dispatcher']);
    Role::query()->create(['name' => 'Team Leader']);

    $dispatcher = User::factory()->create([
        'role_id' => 2,
    ]);

    $offlineLeader = User::factory()->create([
        'role_id' => 3,
        'name' => 'TL Offline',
    ]);

    $this->actingAs($dispatcher)
        ->get(route('admin.drivers'))
        ->assertOk()
        ->assertSee('View All')
        ->assertSee('Online')
        ->assertSee('Offline')
        ->assertSee('Not Available')
        ->assertDontSee('<span class="stat-label">Available</span>', false)
        ->assertSee('data-default-filter="all"', false);

    Cache::put("teamleader:presence:{$offlineLeader->id}", now()->timestamp, now()->addMinutes(2));

    User::factory()->create([
        'role_id' => 3,
        'name' => 'TL Still Offline',
    ]);

    $this->actingAs($dispatcher)
        ->get(route('admin.drivers'))
        ->assertOk()
        ->assertSee('data-default-filter="online"', false);
});

it('disables unit assignment controls and blocks saving for offline team leaders', function () {
    Cache::flush();

    Role::query()->create(['name' => 'Super Admin']);
    Role::query()->create(['name' => 'Dispatcher']);
    Role::query()->create(['name' => 'Team Leader']);

    $dispatcher = User::factory()->create([
        'role_id' => 2,
    ]);

    $teamLeader = User::factory()->create([
        'role_id' => 3,
        'name' => 'TL Offline Lock',
    ]);

    $truckType = TruckType::create([
        'name' => 'Light Duty',
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'max_tonnage' => 4,
        'description' => 'Offline assignment guard test',
    ]);

    $unit = Unit::create([
        'name' => 'Unit 70',
        'plate_number' => 'OFF-7001',
        'truck_type_id' => $truckType->id,
        'status' => 'available',
    ]);

    $this->actingAs($dispatcher)
        ->get(route('admin.drivers'))
        ->assertOk()
        ->assertSee('TL Offline Lock');

    $this->actingAs($dispatcher)
        ->post(route('admin.drivers.assign-unit', $teamLeader), [
            'unit_id' => $unit->id,
        ])
        ->assertRedirect(route('admin.drivers'))
        ->assertSessionHasErrors(['unit_id']);

    expect($unit->fresh()->team_leader_id)->toBeNull();
});

it('shows an active duty queue with ready deployed and unavailable crew states', function () {
    Cache::flush();

    Role::query()->create(['name' => 'Super Admin']);
    Role::query()->create(['name' => 'Dispatcher']);
    Role::query()->create(['name' => 'Team Leader']);

    $dispatcher = User::factory()->create([
        'role_id' => 2,
    ]);

    $readyLeader = User::factory()->create([
        'role_id' => 3,
        'name' => 'TL Ready Crew',
    ]);

    $busyLeader = User::factory()->create([
        'role_id' => 3,
        'name' => 'TL Busy Crew',
    ]);

    User::factory()->create([
        'role_id' => 3,
        'name' => 'TL Offline Crew',
    ]);

    Cache::put("teamleader:presence:{$readyLeader->id}", now()->timestamp, now()->addMinutes(2));
    Cache::put("teamleader:presence:{$busyLeader->id}", now()->timestamp, now()->addMinutes(2));

    $truckType = TruckType::create([
        'name' => 'Queue Visibility Truck',
        'base_rate' => 1600,
        'per_km_rate' => 80,
        'max_tonnage' => 5,
        'description' => 'Queue grouping visibility',
    ]);

    $busyUnit = Unit::create([
        'name' => 'Unit Busy 11',
        'plate_number' => 'BUS-1111',
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $busyLeader->id,
        'status' => 'on_job',
    ]);

    Booking::create([
        'customer_id' => Customer::create([
            'full_name' => 'Queue Test Customer',
            'phone' => '09178889999',
            'email' => 'queue@test.com',
        ])->id,
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

    $this->actingAs($dispatcher)
        ->get(route('admin.drivers'))
        ->assertOk()
        ->assertSee('Online')
        ->assertSee('Currently Deployed')
        ->assertSee('Temporarily Unavailable');
});

it('allows assigning a unit of the same truck type to the same team leader', function () {
    Cache::flush();

    Role::query()->create(['name' => 'Super Admin']);
    Role::query()->create(['name' => 'Dispatcher']);
    Role::query()->create(['name' => 'Team Leader']);

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
        ->post(route('admin.drivers.assign-unit', $teamLeader), [
            'unit_id' => $sameTruckTypeUnit->id,
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

    Role::query()->create(['name' => 'Super Admin']);
    Role::query()->create(['name' => 'Dispatcher']);
    Role::query()->create(['name' => 'Team Leader']);

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
        ->post(route('admin.drivers.assign-unit', $requestingLeader), [
            'unit_id' => $ownedUnit->id,
        ])
        ->assertRedirect(route('admin.drivers'))
        ->assertSessionHasErrors(['unit_id']);

    expect($ownedUnit->fresh()->team_leader_id)->toBe($ownerLeader->id);

    $this->actingAs($dispatcher)
        ->get(route('admin.drivers'))
        ->assertOk()
        ->assertSee('Assigned to', false);
});

it('does not show the redundant available unit status badge on team leader cards', function () {
    Cache::flush();

    Role::query()->create(['name' => 'Super Admin']);
    Role::query()->create(['name' => 'Dispatcher']);
    Role::query()->create(['name' => 'Team Leader']);

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

it('lets the dispatcher update a team leader and unit operational status from the team leaders module', function () {
    Cache::flush();

    Role::query()->create(['name' => 'Super Admin']);
    Role::query()->create(['name' => 'Dispatcher']);
    Role::query()->create(['name' => 'Team Leader']);

    $dispatcher = User::factory()->create([
        'role_id' => 2,
    ]);

    $teamLeader = User::factory()->create([
        'role_id' => 3,
        'name' => 'TL Status Override',
    ]);

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
        'status' => 'available',
    ]);

    Cache::put("teamleader:presence:{$teamLeader->id}", now()->timestamp, now()->addMinutes(2));

    $this->actingAs($dispatcher)
        ->post(route('admin.drivers.update-status', $teamLeader), [
            'operational_status' => 'busy',
            'unit_status' => 'on_job',
            'status_reason' => 'Assigned to active recovery job.',
        ])
        ->assertRedirect(route('admin.drivers'));

    expect($unit->fresh()->status)->toBe('on_job');

    $this->actingAs($dispatcher)
        ->get(route('admin.drivers'))
        ->assertOk()
        ->assertSee('TL Status Override')
        ->assertSee('Assigned to active recovery job.')
        ->assertSee('Busy')
        ->assertSee('On Job');
});

it('shows a zone-based recommendation for dispatch instead of relying on raw gps proximity', function () {
    Cache::flush();

    Role::query()->create(['name' => 'Super Admin']);
    Role::query()->create(['name' => 'Dispatcher']);
    Role::query()->create(['name' => 'Team Leader']);

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
        'status' => 'available',
    ]);

    $makatiUnit = Unit::create([
        'name' => 'Makati Unit 7',
        'plate_number' => 'MKT-7007',
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $makatiLeader->id,
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

    $this->actingAs($dispatcher)
        ->get(route('admin.dispatch'))
        ->assertOk()
        ->assertSee($incomingBooking->job_code)
        ->assertSee('Makati Zone')
        ->assertSee('Recommended unit')
        ->assertSee('Makati Unit 7');
});

it('redirects logout to the login page on the configured local app url', function () {
    config()->set('app.url', 'http://127.0.0.1:8000');

    Role::query()->create(['name' => 'Team Leader']);

    $teamLeader = User::factory()->create([
        'role_id' => 1,
    ]);

    $this->actingAs($teamLeader)
        ->withServerVariables(['HTTP_HOST' => '127.0.0.1:8001'])
        ->post(route('logout'))
        ->assertRedirect('http://127.0.0.1:8000/login');
});
