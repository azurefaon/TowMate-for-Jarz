<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\UnitCrewLoan;
use App\Models\User;
use App\Services\PersonnelService;
use App\Services\UnitTeamAssignmentService;

function opcOwner(): User
{
    foreach ([1 => 'Owner', 2 => 'Admin', 3 => 'Team Leader'] as $id => $name) {
        if (! Role::find($id)) {
            $role = new Role(['name' => $name]);
            $role->id = $id;
            $role->save();
        }
    }

    return User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
}

function opcUnit(string $name, array $attrs = []): Unit
{
    $type = TruckType::first() ?? TruckType::create(['name' => 'OPC Truck', 'base_rate' => 1500, 'per_km_rate' => 60]);

    return Unit::create(array_merge([
        'name' => $name, 'plate_number' => 'OPC-' . random_int(1000, 9999),
        'truck_type_id' => $type->id, 'status' => 'available',
    ], $attrs));
}

function opcDriver(string $first = 'Kevin Michael', string $last = 'Camaya', array $attrs = []): Personnel
{
    return Personnel::create(array_merge(['first_name' => $first, 'last_name' => $last, 'role' => 'driver', 'personnel_status' => 'active'], $attrs));
}

function opcRow(string $search): array
{
    return app(PersonnelService::class)->listPersonnel($search)->first();
}

it('shows the current unit for a name-only placement', function () {
    $kevin = opcDriver();
    $unit = opcUnit('OPC JARZ 3', ['driver_name' => 'kevin  michael camaya']);

    $row = opcRow('Kevin');

    expect($row['current_unit']->id)->toBe($unit->id)->and($row['assignment_status'])->toBe('Assigned');
});

it('shows a borrowed driver on the current unit while the regular unit stays put', function () {
    $home = opcUnit('OPC JARZ 4');
    $kevin = opcDriver(attrs: ['home_unit_id' => $home->id]);
    $home->update(['driver_name' => $kevin->full_name, 'driver_personnel_id' => $kevin->id]);
    $away = opcUnit('OPC JARZ 3');
    $dispatcher = User::factory()->create(['role_id' => 2]);
    app(UnitTeamAssignmentService::class)->assignSlotPerson($away, 'driver_1', $home, 'driver_1', $dispatcher);

    $row = opcRow('Kevin');

    expect($row['home_unit']->id)->toBe($home->id)
        ->and($row['current_unit']->id)->toBe($away->id)
        ->and($row['assignment_status'])->toBe('Borrowed')
        ->and($kevin->fresh()->home_unit_id)->toBe($home->id);

    $this->actingAs(opcOwner())->get(route('superadmin.personnel.index'))
        ->assertOk()->assertSee('Regular: OPC JARZ 4')->assertSee('Current: OPC JARZ 3');
});

it('shows the destination unit for an open name-only loan', function () {
    $home = opcUnit('OPC JARZ 4');
    $away = opcUnit('OPC JARZ 3');
    opcDriver(attrs: ['home_unit_id' => $home->id]);
    UnitCrewLoan::create([
        'from_unit_id' => $home->id, 'to_unit_id' => $away->id,
        'from_slot' => 'driver_1', 'to_slot' => 'driver_1',
        'person_name' => 'Kevin Michael Camaya', 'borrowed_at' => now(),
    ]);

    $row = opcRow('Kevin');

    expect($row['current_unit']->id)->toBe($away->id)->and($row['assignment_status'])->toBe('Borrowed');
});

it('does not show a duplicate personnel row as unassigned when the person is operationally engaged', function () {
    $placed = opcDriver();
    $duplicate = opcDriver();
    $unit = opcUnit('OPC JARZ 3', ['driver_name' => $placed->full_name, 'driver_personnel_id' => $placed->id]);

    $rows = app(PersonnelService::class)->listPersonnel('Kevin')->getCollection();

    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn ($r) => $r['current_unit']?->id === $unit->id))->toBeTrue()
        ->and($rows->contains(fn ($r) => $r['assignment_status'] === 'Unassigned'))->toBeFalse();
});

it('shows the job unit for a driver named on an active job', function () {
    $kevin = opcDriver();
    $unit = opcUnit('OPC JARZ 3', ['status' => 'on_job']);
    $leader = User::factory()->create(['role_id' => 3]);
    $unit->update(['team_leader_id' => $leader->id]);
    Booking::create([
        'customer_id' => Customer::create(['full_name' => 'OPC Cust', 'age' => 30, 'phone' => '09171234567', 'email' => 'opc@example.test'])->id,
        'truck_type_id' => $unit->truck_type_id, 'assigned_unit_id' => $unit->id, 'assigned_team_leader_id' => $leader->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 1800,
        'status' => 'on_the_way', 'driver_name' => $kevin->full_name, 'assigned_at' => now(),
    ]);

    $row = opcRow('Kevin');

    expect($row['current_unit']->id)->toBe($unit->id)->and($row['availability'])->toBe('Busy');
});

it('still shows a truly free person as unassigned, and again once a loan ends', function () {
    $home = opcUnit('OPC JARZ 4');
    $away = opcUnit('OPC JARZ 3');
    opcDriver('Free', 'Person');
    $loan = UnitCrewLoan::create([
        'from_unit_id' => $home->id, 'to_unit_id' => $away->id,
        'from_slot' => 'driver_1', 'to_slot' => 'driver_1',
        'person_name' => 'Free Person', 'borrowed_at' => now(),
    ]);

    expect(opcRow('Free')['assignment_status'])->toBe('Assigned');

    $loan->update(['returned_at' => now()]);

    $row = opcRow('Free');
    expect($row['current_unit'])->toBeNull()->and($row['assignment_status'])->toBe('Unassigned');
});
