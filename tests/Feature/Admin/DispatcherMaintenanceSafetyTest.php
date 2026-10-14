<?php

/*
 * Dispatcher maintenance (units.maintenance) is protected like the Owner
 * toggle: an active job or a reservation, an archived truck, or an on_job
 * status refuses it, nothing is changed or audited on a refusal, earlier
 * maintenance notes are never overwritten, and the old available-units.toggle
 * URL is gone.
 */

use App\Http\Controllers\Admin\AvailableUnitsController;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

function dmUser(int $roleId, string $name): User
{
    DB::table('roles')->insertOrIgnore([
        ['id' => 1, 'name' => 'Owner', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'Admin', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'name' => 'Team Leader', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 4, 'name' => 'Driver', 'created_at' => now(), 'updated_at' => now()],
    ]);

    return User::factory()->create(['role_id' => $roleId, 'name' => $name, 'status' => 'active', 'must_change_password' => false]);
}

function dmUnit(array $attrs = []): Unit
{
    return Unit::create(array_merge([
        'name' => 'DM ' . uniqid(),
        'plate_number' => 'DM-' . uniqid(),
        'truck_type_id' => (TruckType::first() ?? TruckType::create(['name' => 'DM Truck', 'base_rate' => 1500, 'per_km_rate' => 75]))->id,
        'status' => 'available',
    ], $attrs));
}

function dmBooking(Unit $unit, string $status, array $attrs = []): Booking
{
    return Booking::create(array_merge([
        'customer_id' => Customer::create(['full_name' => 'DM Customer', 'age' => 30, 'phone' => '09171234567', 'email' => 'dm-' . uniqid() . '@example.test'])->id,
        'truck_type_id' => $unit->truck_type_id,
        'age' => 30,
        'pickup_address' => 'Quezon City Circle', 'pickup_lat' => 14.676, 'pickup_lng' => 121.0437,
        'dropoff_address' => 'SM Megamall', 'dropoff_lat' => 14.5842, 'dropoff_lng' => 121.0568,
        'distance_km' => 6, 'base_rate' => 1500, 'per_km_rate' => 75,
        'computed_total' => 1950, 'final_total' => 1950, 'vat_exclusive_total' => 1950,
        'status' => $status,
    ], $attrs));
}

function dmMaintenance(User $dispatcher, Unit $unit, array $data = ['reason' => 'Brake noise'])
{
    return test()->actingAs($dispatcher)->postJson(route('admin.units.maintenance', $unit), $data);
}

/** Audit rows other than the create_* ones written by fixtures. */
function dmAudits(): int
{
    return DB::table('audit_logs')->where('action', 'not like', 'create\_%')->count();
}

it('retires the available-units.toggle route and leaves data untouched', function () {
    $dispatcher = dmUser(2, 'DM Dispatcher');
    $unit = dmUnit(['status' => 'available']);
    $before = DB::table('units')->where('id', $unit->id)->first();
    $audits = dmAudits();

    expect(Route::has('admin.available-units.toggle'))->toBeFalse()
        ->and(method_exists(AvailableUnitsController::class, 'toggle'))->toBeFalse();

    $this->actingAs($dispatcher)->patch("/admin-dashboard/available-units/{$unit->id}/toggle")->assertNotFound();
    $this->actingAs($dispatcher)->patchJson("/admin-dashboard/available-units/{$unit->id}/toggle")->assertNotFound();

    expect(DB::table('units')->where('id', $unit->id)->first())->toEqual($before)
        ->and(dmAudits())->toBe($audits);
});

it('moves a free truck into maintenance, keeps the JSON contract and audits old and new values', function () {
    $dispatcher = dmUser(2, 'DM Dispatcher');
    $unit = dmUnit();

    dmMaintenance($dispatcher, $unit)
        ->assertOk()
        ->assertExactJson(['success' => true, 'message' => 'Unit marked for maintenance successfully.']);

    $fresh = $unit->fresh();
    expect($fresh->status)->toBe('maintenance')->and($fresh->issue_note)->toContain('Brake noise')->toContain('DM Dispatcher');

    $rows = AuditLog::where('action', 'unit_marked_maintenance')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->old_value['status'])->toBe('available')
        ->and($rows[0]->new_value['status'])->toBe('maintenance');
});

it('refuses a truck with an active job and changes nothing', function (string $status) {
    $dispatcher = dmUser(2, 'DM Dispatcher');
    $leader = dmUser(3, 'DM Leader');
    $unit = dmUnit(['team_leader_id' => $leader->id]);
    $booking = dmBooking($unit, $status, ['assigned_unit_id' => $unit->id, 'assigned_team_leader_id' => $leader->id, 'assigned_at' => now()]);
    $before = DB::table('units')->where('id', $unit->id)->first();
    $audits = dmAudits();

    $response = dmMaintenance($dispatcher, $unit)->assertStatus(422)->assertJson(['success' => false]);
    expect($response->json('message'))->toContain((string) $booking->job_code)->toContain('Resolve or reassign');

    expect(DB::table('units')->where('id', $unit->id)->first())->toEqual($before)
        ->and($booking->fresh()->status)->toBe($status)
        ->and(dmAudits())->toBe($audits);
})->with(['assigned', 'accepted', 'on_the_way', 'in_progress']);

it('refuses a reserved truck and changes nothing', function () {
    $dispatcher = dmUser(2, 'DM Dispatcher');
    $unit = dmUnit();
    $booking = dmBooking($unit, 'quoted', ['selected_unit_id' => $unit->id]);
    $before = DB::table('units')->where('id', $unit->id)->first();
    $audits = dmAudits();

    $response = dmMaintenance($dispatcher, $unit)->assertStatus(422);
    expect($response->json('message'))->toContain('reserved')->toContain((string) $booking->job_code);

    expect(DB::table('units')->where('id', $unit->id)->first())->toEqual($before)
        ->and($booking->fresh()->selected_unit_id)->toBe($unit->id)
        ->and(dmAudits())->toBe($audits);
});

it('does not let the current booking id bypass the protection', function () {
    $dispatcher = dmUser(2, 'DM Dispatcher');
    $leader = dmUser(3, 'DM Leader');
    $unit = dmUnit(['team_leader_id' => $leader->id]);
    $booking = dmBooking($unit, 'accepted', ['assigned_unit_id' => $unit->id, 'assigned_team_leader_id' => $leader->id, 'assigned_at' => now()]);

    dmMaintenance($dispatcher, $unit, ['reason' => 'Vehicle issue', 'booking_id' => (string) $booking->id])->assertStatus(422);
    dmMaintenance($dispatcher, $unit, ['reason' => 'Vehicle issue', 'booking_id' => (string) $booking->booking_code])->assertStatus(422);

    expect($unit->fresh()->status)->toBe('available')
        ->and($booking->fresh()->status)->toBe('accepted')
        ->and($booking->fresh()->assigned_unit_id)->toBe($unit->id);
});

it('refuses an archived truck and a truck whose status is on_job', function () {
    $dispatcher = dmUser(2, 'DM Dispatcher');
    $archived = dmUnit(['archived_at' => now()]);
    $onJob = dmUnit(['status' => 'on_job']);
    $audits = dmAudits();

    expect(dmMaintenance($dispatcher, $archived)->assertStatus(422)->json('message'))->toContain('archived');
    expect(dmMaintenance($dispatcher, $onJob)->assertStatus(422)->json('message'))->toContain('on a job');

    expect($archived->fresh()->status)->toBe('available')
        ->and($onJob->fresh()->status)->toBe('on_job')
        ->and(dmAudits())->toBe($audits);
});

it('keeps existing maintenance notes and appends the new reason', function () {
    $dispatcher = dmUser(2, 'DM Dispatcher');
    $unit = dmUnit(['issue_note' => 'Owner note: engine overheating']);

    dmMaintenance($dispatcher, $unit, ['reason' => 'Flat tyre'])->assertOk();

    $note = $unit->fresh()->issue_note;
    expect($note)->toStartWith('Owner note: engine overheating')->toContain('Flat tyre')->toContain('DM Dispatcher');

    // The same reason sent again is not duplicated.
    $unit->update(['status' => 'available']);
    dmMaintenance($dispatcher, $unit->fresh(), ['reason' => 'Owner note: engine overheating'])->assertOk();
    expect($unit->fresh()->issue_note)->toBe($note);
});

it('appends to a very long existing note without truncating or refusing', function () {
    $dispatcher = dmUser(2, 'DM Dispatcher');
    $long = str_repeat('x', 8000);
    $unit = dmUnit(['issue_note' => $long]);

    dmMaintenance($dispatcher, $unit, ['reason' => 'Another reason'])->assertOk();

    expect($unit->fresh()->issue_note)->toStartWith($long)->toContain('Another reason')->and($unit->fresh()->status)->toBe('maintenance');
});

it('still validates the request and keeps the endpoint dispatcher-only', function () {
    $dispatcher = dmUser(2, 'DM Dispatcher');
    $driver = dmUser(4, 'DM Driver');
    $unit = dmUnit();

    dmMaintenance($dispatcher, $unit, [])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->actingAs($driver)->postJson(route('admin.units.maintenance', $unit), ['reason' => 'x'])->assertForbidden();

    expect($unit->fresh()->status)->toBe('available');
});
