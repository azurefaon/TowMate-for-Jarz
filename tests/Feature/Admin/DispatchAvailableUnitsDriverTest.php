<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

function availDriverDispatcher(): User
{
    $role = Role::find(2) ?? tap(new Role(['name' => 'Dispatcher', 'description' => 'Dispatch staff']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id]);
}

function availDriverTruckType(string $label = 'Avail Driver Truck'): TruckType
{
    return TruckType::create([
        'name' => $label . ' ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 300,
    ]);
}

function availDriverTeamLeader(bool $online = true): User
{
    $role = Role::firstOrCreate(['name' => 'Team Leader'], ['description' => 'Tow unit team leader']);
    $teamLeader = User::factory()->create(['role_id' => $role->id]);

    if ($online) {
        Cache::put("teamleader:presence:{$teamLeader->id}", now()->timestamp, now()->addMinutes(2));
    }

    return $teamLeader;
}

function availDriverAvailableUnits($response): \Illuminate\Support\Collection
{
    return collect($response->viewData('availableUnits'));
}

it('treats a unit with a free-text driver_name and no driver_id as having a driver', function () {
    $dispatcher = availDriverDispatcher();
    $truckType = availDriverTruckType();
    $teamLeader = availDriverTeamLeader();

    $unit = Unit::create([
        'name' => 'Free Text Driver Unit',
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
        'driver_name' => 'PAULO PAULO',
    ]);

    $response = $this->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk();

    $units = availDriverAvailableUnits($response);

    expect($units->pluck('id'))->toContain($unit->id);
    expect($units->firstWhere('id', $unit->id)['driver_name'])->toBe('PAULO PAULO');
});

it('still treats a unit with a legacy linked driver_id account as having a driver', function () {
    $dispatcher = availDriverDispatcher();
    $truckType = availDriverTruckType();
    $teamLeader = availDriverTeamLeader();

    $driverRole = Role::firstOrCreate(['name' => 'Driver'], ['description' => 'Linked driver account']);
    $linkedDriver = User::factory()->create(['role_id' => $driverRole->id, 'name' => 'Legacy Linked Driver']);

    $unit = Unit::create([
        'name' => 'Legacy Driver Unit',
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
        'driver_id' => $linkedDriver->id,
    ]);

    $response = $this->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk();

    $units = availDriverAvailableUnits($response);

    expect($units->pluck('id'))->toContain($unit->id);
    expect($units->firstWhere('id', $unit->id)['driver_name'])->toBe('Legacy Linked Driver');
});

it('excludes a unit with neither driver_id nor driver_name', function () {
    $dispatcher = availDriverDispatcher();
    $truckType = availDriverTruckType();
    $teamLeader = availDriverTeamLeader();

    $unit = Unit::create([
        'name' => 'No Driver Unit',
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
    ]);

    $response = $this->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk();

    $units = availDriverAvailableUnits($response);

    expect($units->pluck('id'))->not->toContain($unit->id);
});

it('still includes a unit whose team leader is offline as long as it has a driver_name set', function () {
    $dispatcher = availDriverDispatcher();
    $truckType = availDriverTruckType();
    $teamLeader = availDriverTeamLeader(online: false);

    $unit = Unit::create([
        'name' => 'Offline Leader Unit',
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
        'driver_name' => 'Some Driver',
    ]);

    $response = $this->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk();

    $units = availDriverAvailableUnits($response);

    expect($units->pluck('id'))->toContain($unit->id);
    expect($units->firstWhere('id', $unit->id)['status_summary'])->toContain('Available for dispatch');
});

it('still excludes a unit whose team leader has an active job', function () {
    $dispatcher = availDriverDispatcher();
    $truckType = availDriverTruckType();
    $teamLeader = availDriverTeamLeader();

    $unit = Unit::create([
        'name' => 'Busy Leader Unit',
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
        'driver_name' => 'Some Driver',
    ]);

    $customer = Customer::create([
        'full_name' => 'Avail Driver Customer',
        'phone' => '09170000099',
        'email' => 'avail-driver-' . uniqid() . '@example.com',
    ]);

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pickup',
        'dropoff_address' => 'Dropoff',
        'distance_km' => 10,
        'base_rate' => 1500,
        'per_km_rate' => 300,
        'computed_total' => 4500,
        'final_total' => 4500,
        'status' => 'accepted',
        'service_type' => 'book_now',
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $teamLeader->id,
        'assigned_at' => now(),
    ]);

    $response = $this->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk();

    $units = availDriverAvailableUnits($response);

    expect($units->pluck('id'))->not->toContain($unit->id);
});

it('attaches the correct truck type to an available unit profile', function () {
    $dispatcher = availDriverDispatcher();
    $truckType = availDriverTruckType('Heavy Lift');
    $teamLeader = availDriverTeamLeader();

    $unit = Unit::create([
        'name' => 'Truck Type Unit',
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
        'driver_name' => 'Some Driver',
    ]);

    $response = $this->actingAs($dispatcher)->get(route('admin.dispatch'))->assertOk();

    $units = availDriverAvailableUnits($response);
    $profile = $units->firstWhere('id', $unit->id);

    expect($profile)->not->toBeNull();
    expect($profile['truck_type_id'])->toBe($truckType->id);
    expect($profile['selectable'])->toBeTrue();
});
