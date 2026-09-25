<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\UnitCrewLoan;
use App\Models\User;

function auRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function auOwner(): User
{
    auRole(1, 'Owner');

    return User::factory()->create(['role_id' => 1, 'status' => 'active']);
}

function auDispatcher(): User
{
    auRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active']);
}

function auTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'AU Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
    ]);
}

function auCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'AU Test Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'au-' . uniqid() . '@example.com',
    ]);
}

function auArchivedUnit(array $overrides = []): Unit
{
    return Unit::create(array_merge([
        'name' => 'AU Unit ' . fake()->unique()->numerify('####'),
        'plate_number' => fake()->unique()->bothify('???####'),
        'truck_type_id' => auTruckType()->id,
        'status' => 'available',
        'archived_at' => now(),
    ], $overrides));
}

it('owner can access archived units', function () {
    $this->actingAs(auOwner())->get(route('superadmin.units.archived'))->assertOk();
});

it('dispatcher cannot access owner archived units', function () {
    $this->actingAs(auDispatcher())->get(route('superadmin.units.archived'))->assertForbidden();
});

it('links back to trucks with the correct route', function () {
    $response = $this->actingAs(auOwner())->get(route('superadmin.units.archived'));

    $response->assertOk();
    $response->assertSee('href="' . route('superadmin.unit-truck.index') . '"', false);
    $response->assertSee('Back to Trucks');
});

it('renders archived unit records', function () {
    $unit = auArchivedUnit();

    $response = $this->actingAs(auOwner())->get(route('superadmin.units.archived'));

    $response->assertOk();
    $response->assertSee($unit->name);
    $response->assertSee(strtoupper($unit->plate_number));
});

it('renders a compact empty state when no archived units exist', function () {
    $response = $this->actingAs(auOwner())->get(route('superadmin.units.archived'));

    $response->assertOk();
    $response->assertSee('No archived trucks');
    $response->assertSee('Trucks archived from the active fleet will appear here.');
});

it('continues to support searching archived units by unit name or plate', function () {
    $matching = auArchivedUnit(['name' => 'AU Findable Unit']);
    $other = auArchivedUnit();

    $response = $this->actingAs(auOwner())->get(route('superadmin.units.archived', ['search' => 'Findable']));

    $response->assertOk();
    $response->assertSee($matching->name);
    $response->assertDontSee($other->name);
});

it('keeps restore wired to the exact existing PATCH route with CSRF', function () {
    $unit = auArchivedUnit();

    $response = $this->actingAs(auOwner())->get(route('superadmin.units.archived'));

    $response->assertOk();
    $response->assertSee('action="' . route('superadmin.units.restore', $unit->id) . '"', false);
    $response->assertSee('name="_method" value="PATCH"', false);
    $response->assertSee('name="_token"', false);
});

it('keeps permanent delete wired to the exact existing DELETE route with CSRF', function () {
    $unit = auArchivedUnit();

    $response = $this->actingAs(auOwner())->get(route('superadmin.units.archived'));

    $response->assertOk();
    $response->assertSee('action="' . route('superadmin.units.force-delete', $unit->id) . '"', false);
    $response->assertSee('name="_method" value="DELETE"', false);
});

it('restore actually clears archived_at via the existing route and method', function () {
    $owner = auOwner();
    $unit = auArchivedUnit();

    $response = $this->actingAs($owner)->patch(route('superadmin.units.restore', $unit->id));

    $response->assertRedirect(route('superadmin.units.archived'));
    $this->assertDatabaseHas('units', ['id' => $unit->id, 'archived_at' => null]);
});

it('permanently deletes a unit referenced only by a completed historical booking, nulling its FK and keeping the snapshot', function () {
    $owner = auOwner();
    $unit = auArchivedUnit(['name' => 'AU Completed Job Unit', 'plate_number' => 'CMP1234']);
    $customer = auCustomer();

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'assigned_unit_id' => $unit->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'completed',
    ]);

    $response = $this->actingAs($owner)->delete(route('superadmin.units.force-delete', $unit->id));

    $response->assertRedirect(route('superadmin.units.archived'));
    $this->assertDatabaseMissing('units', ['id' => $unit->id]);
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'assigned_unit_id' => null]);
    expect($booking->fresh()->display_unit_name)->toBe('AU Completed Job Unit')
        ->and($booking->fresh()->display_unit_plate_number)->toBe('CMP1234');
});

it('blocks permanent delete when the unit is referenced by an active operational booking', function () {
    $owner = auOwner();
    $unit = auArchivedUnit();
    $customer = auCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'assigned_unit_id' => $unit->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'on_the_way',
    ]);

    $response = $this->actingAs($owner)->delete(route('superadmin.units.force-delete', $unit->id));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    $this->assertDatabaseHas('units', ['id' => $unit->id]);
});

it('blocks permanent delete when a historical booking is missing its unit snapshot', function () {
    $owner = auOwner();
    $unit = auArchivedUnit(['name' => 'AU No Snapshot Unit', 'plate_number' => 'NSN1234']);
    $customer = auCustomer();

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'assigned_unit_id' => $unit->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'completed',
    ]);

    \Illuminate\Support\Facades\DB::table('bookings')->where('id', $booking->id)->update([
        'assigned_unit_name' => null,
        'assigned_unit_plate_number' => null,
    ]);

    $response = $this->actingAs($owner)->delete(route('superadmin.units.force-delete', $unit->id));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    $this->assertDatabaseHas('units', ['id' => $unit->id]);
});

it('permanently deletes a unit referenced only through a terminal selected_unit_id booking when its snapshot is complete', function () {
    $owner = auOwner();
    $unit = auArchivedUnit(['name' => 'AU Selected Snapshot Unit', 'plate_number' => 'SEL1234']);
    $customer = auCustomer();

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'requested',
    ]);

    \Illuminate\Support\Facades\DB::table('bookings')->where('id', $booking->id)->update([
        'status' => 'cancelled',
        'selected_unit_id' => $unit->id,
        'selected_unit_name' => $unit->name,
        'selected_unit_plate_number' => $unit->plate_number,
    ]);

    $response = $this->actingAs($owner)->delete(route('superadmin.units.force-delete', $unit->id));

    $response->assertRedirect(route('superadmin.units.archived'));
    $this->assertDatabaseMissing('units', ['id' => $unit->id]);
    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'selected_unit_id' => null]);
    expect($booking->fresh()->selected_unit_name)->toBe('AU Selected Snapshot Unit')
        ->and($booking->fresh()->selected_unit_plate_number)->toBe('SEL1234');
});

it('blocks permanent delete when a terminal selected_unit_id booking is missing its snapshot', function () {
    $owner = auOwner();
    $unit = auArchivedUnit();
    $customer = auCustomer();

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'requested',
    ]);

    \Illuminate\Support\Facades\DB::table('bookings')->where('id', $booking->id)->update([
        'status' => 'cancelled',
        'selected_unit_id' => $unit->id,
        'selected_unit_name' => null,
        'selected_unit_plate_number' => null,
    ]);

    $response = $this->actingAs($owner)->delete(route('superadmin.units.force-delete', $unit->id));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    $this->assertDatabaseHas('units', ['id' => $unit->id]);
});

it('revalidates booking references fresh at delete time instead of trusting an earlier page load', function () {
    $owner = auOwner();
    $unit = auArchivedUnit();
    $customer = auCustomer();

    $indexResponse = $this->actingAs($owner)->get(route('superadmin.units.archived'));
    $indexResponse->assertOk();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'assigned_unit_id' => $unit->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'on_the_way',
    ]);

    $response = $this->actingAs($owner)->delete(route('superadmin.units.force-delete', $unit->id));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    $this->assertDatabaseHas('units', ['id' => $unit->id]);
});

it('does not write an audit log entry when permanent delete is blocked', function () {
    $owner = auOwner();
    $unit = auArchivedUnit();
    $customer = auCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'assigned_unit_id' => $unit->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'on_the_way',
    ]);

    $this->actingAs($owner)->delete(route('superadmin.units.force-delete', $unit->id));

    $this->assertDatabaseMissing('audit_logs', [
        'entity_type' => 'Unit',
        'entity_id' => $unit->id,
        'action' => 'unit_permanently_deleted',
    ]);
});

it('blocks permanent delete when the unit has an active crew loan', function () {
    $owner = auOwner();
    $unit = auArchivedUnit();
    $otherUnit = auArchivedUnit();

    UnitCrewLoan::create([
        'from_unit_id' => $otherUnit->id,
        'to_unit_id' => $unit->id,
        'from_slot' => 'driver_1',
        'to_slot' => 'driver_1',
        'person_name' => 'Loaned Driver',
        'borrowed_at' => now(),
    ]);

    $response = $this->actingAs($owner)->delete(route('superadmin.units.force-delete', $unit->id));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    $this->assertDatabaseHas('units', ['id' => $unit->id]);
});

it('permanently deletes an archived unit with no booking history or active loans', function () {
    $owner = auOwner();
    $unit = auArchivedUnit();

    $response = $this->actingAs($owner)->delete(route('superadmin.units.force-delete', $unit->id));

    $response->assertRedirect(route('superadmin.units.archived'));
    $this->assertDatabaseMissing('units', ['id' => $unit->id]);
});

it('blocks permanent delete when the unit is only referenced via selected_unit_id', function () {
    $owner = auOwner();
    $unit = auArchivedUnit();
    $customer = auCustomer();

    tap(new Booking())->forceFill([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'selected_unit_id' => $unit->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'quoted',
    ])->save();

    $response = $this->actingAs($owner)->delete(route('superadmin.units.force-delete', $unit->id));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    $this->assertDatabaseHas('units', ['id' => $unit->id]);
});

it('stores a historical unit name and plate snapshot when a booking is assigned a unit', function () {
    $customer = auCustomer();
    $unit = Unit::create([
        'name' => 'AU Snapshot Unit',
        'plate_number' => 'ABC1234',
        'truck_type_id' => auTruckType()->id,
        'status' => 'available',
    ]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'assigned_unit_id' => $unit->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'assigned',
    ]);

    expect($booking->fresh()->assigned_unit_name)->toBe('AU Snapshot Unit')
        ->and($booking->fresh()->assigned_unit_plate_number)->toBe('ABC1234');
});

it('updates the historical snapshot when a booking is reassigned to a different unit', function () {
    $customer = auCustomer();
    $truckType = auTruckType();
    $firstUnit = Unit::create([
        'name' => 'AU First Unit', 'plate_number' => 'AAA1111',
        'truck_type_id' => $truckType->id, 'status' => 'available',
    ]);
    $secondUnit = Unit::create([
        'name' => 'AU Second Unit', 'plate_number' => 'BBB2222',
        'truck_type_id' => $truckType->id, 'status' => 'available',
    ]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $firstUnit->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'assigned',
    ]);

    $booking->update(['assigned_unit_id' => $secondUnit->id]);

    expect($booking->fresh()->assigned_unit_name)->toBe('AU Second Unit')
        ->and($booking->fresh()->assigned_unit_plate_number)->toBe('BBB2222');
});

it('backfilled bookings without a live model event still survive unit deletion once backfilled', function () {
    $owner = auOwner();
    $customer = auCustomer();
    $truckType = auTruckType();
    $unit = Unit::create([
        'name' => 'AU Backfill Unit', 'plate_number' => 'BFL9999',
        'truck_type_id' => $truckType->id, 'status' => 'available',
    ]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800,
        'final_total' => 1800.00,
        'status' => 'completed',
    ]);

    \Illuminate\Support\Facades\DB::table('bookings')->where('id', $booking->id)->update([
        'assigned_unit_name' => null,
        'assigned_unit_plate_number' => null,
    ]);

    (require database_path('migrations/2026_09_25_120100_backfill_unit_snapshot_fields_on_bookings_table.php'))->up();

    $unit->update(['archived_at' => now()]);
    $response = $this->actingAs($owner)->delete(route('superadmin.units.force-delete', $unit->id));

    $response->assertRedirect(route('superadmin.units.archived'));
    $this->assertDatabaseMissing('units', ['id' => $unit->id]);
    expect($booking->fresh()->display_unit_name)->toBe('AU Backfill Unit')
        ->and($booking->fresh()->display_unit_plate_number)->toBe('BFL9999');
});

it('cannot select or restore a permanently deleted unit as the same record', function () {
    $owner = auOwner();
    $unit = auArchivedUnit();
    $unitId = $unit->id;

    $this->actingAs($owner)->delete(route('superadmin.units.force-delete', $unitId));

    $this->assertDatabaseMissing('units', ['id' => $unitId]);
    expect(Unit::find($unitId))->toBeNull();
});

it('creates a brand new unit record when the same plate number is re-added after deletion', function () {
    $owner = auOwner();
    $unit = auArchivedUnit(['plate_number' => 'REUSE123']);
    $originalId = $unit->id;

    $this->actingAs($owner)->delete(route('superadmin.units.force-delete', $originalId));
    $this->assertDatabaseMissing('units', ['id' => $originalId]);

    $newUnit = Unit::create([
        'name' => 'AU Reused Plate Unit',
        'plate_number' => 'REUSE123',
        'truck_type_id' => auTruckType()->id,
        'status' => 'available',
    ]);

    expect($newUnit->id)->not->toBe($originalId);
    $this->assertDatabaseHas('units', ['id' => $newUnit->id, 'plate_number' => 'REUSE123']);
});

it('does not expose personnel mutation controls on the archived units page', function () {
    auArchivedUnit();

    $response = $this->actingAs(auOwner())->get(route('superadmin.units.archived'));

    $response->assertOk();
    $response->assertDontSee('assign-team-leader', false);
    $response->assertDontSee('js-assign-leader', false);
    $response->assertDontSee('remove-team-leader', false);
    $response->assertDontSee('transfer-team', false);
    $response->assertDontSee('borrow-crew', false);
});

it('renders a three-dot action trigger with Restore and Delete Permanently instead of permanent text buttons', function () {
    $unit = auArchivedUnit();

    $response = $this->actingAs(auOwner())->get(route('superadmin.units.archived'));

    $response->assertOk();
    $response->assertSee('aria-label="Actions for ' . $unit->name . '"', false);
    $response->assertSee('Restore Unit');
    $response->assertSee('Delete Permanently');
    $response->assertDontSee('class="action-btn restore-btn"', false);
    $response->assertDontSee('class="action-btn archive-btn"', false);
});

it('does not render the shared fleet tabs as a fourth Archived Units tab', function () {
    $response = $this->actingAs(auOwner())->get(route('superadmin.units.archived'));

    $response->assertOk();
    $response->assertDontSee('class="fleet-tabs"', false);
});

it('keeps Fleet Management highlighted in the sidebar while on Archived Units', function () {
    $response = $this->actingAs(auOwner())->get(route('superadmin.units.archived'));

    $response->assertOk();
    expect(preg_match('/title="Fleet Management"\s*class="active"/', $response->getContent()))->toBe(1);
});
