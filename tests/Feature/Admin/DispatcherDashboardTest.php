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
        ->assertSee('Online Only')
        ->assertSee('Offline Only')
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
