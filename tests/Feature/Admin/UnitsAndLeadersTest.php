<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\UnitCrewLoan;
use App\Models\User;
use App\Services\UnitAvailabilityService;
use App\Services\UnitTeamAssignmentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

function ulRoles(): void
{
    DB::table('roles')->insertOrIgnore([
        ['id' => 1, 'name' => 'Owner', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'Admin', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'name' => 'Team Leader', 'created_at' => now(), 'updated_at' => now()],
    ]);
}

function ulDispatcher(): User
{
    return User::factory()->create(['role_id' => 2]);
}

function ulOwner(): User
{
    return User::factory()->create(['role_id' => 1]);
}

function ulTeamLeader(string $name = null): User
{
    return User::factory()->create(['role_id' => 3, 'name' => $name ?? ('TL ' . uniqid())]);
}

function ulTruckType(string $class = 'light'): TruckType
{
    return TruckType::create([
        'name' => ucfirst($class) . ' Truck ' . uniqid(),
        'class' => $class,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'max_tonnage' => 5,
        'description' => 'UL test truck',
        'status' => 'active',
    ]);
}

function ulUnit(array $overrides = []): Unit
{
    $truckType = $overrides['truck_type'] ?? ulTruckType();

    return Unit::create(array_merge([
        'name' => 'JARZ ' . uniqid(),
        'plate_number' => strtoupper(substr(uniqid(), 0, 3)) . rand(1000, 9999),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
    ], array_diff_key($overrides, ['truck_type' => null])));
}

function ulCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'UL Customer ' . uniqid(),
        'age' => 30,
        'phone' => '09171234567',
        'email' => uniqid() . '@example.test',
    ]);
}

it('1. Owner can still create/edit/manage truck assets', function () {
    ulRoles();
    $owner = ulOwner();
    $truckType = ulTruckType();

    $this->actingAs($owner)
        ->post(route('superadmin.units.store'), [
            'plate_number' => 'ABC1234',
            'truck_type_id' => $truckType->id,
        ])
        ->assertRedirect();

    expect(Unit::where('plate_number', 'ABC1234')->exists())->toBeTrue();

    $unit = Unit::where('plate_number', 'ABC1234')->first();

    $this->actingAs($owner)
        ->patch(route('superadmin.units.toggle', $unit->id))
        ->assertRedirect();

    expect($unit->fresh()->status)->toBe('maintenance');
});

it('2. Owner cannot perform personnel assignment through Units Overview UI', function () {
    ulRoles();
    $owner = ulOwner();
    $unit = ulUnit();

    $html = $this->actingAs($owner)
        ->get(route('superadmin.unit-truck.index'))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('js-assign-leader')
        ->and($html)->not->toContain('js-assign-crew')
        ->and($html)->not->toContain('remove-team-leader');
});

it('3. Dispatcher can Assign an unassigned Team Leader to an empty slot', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $unit = ulUnit();
    $tl = ulTeamLeader();

    app(UnitTeamAssignmentService::class)->assignTeamLeader($unit, $tl->id, $dispatcher);

    expect($unit->fresh()->team_leader_id)->toBe($tl->id);
});

it('4. Dispatcher can borrow a Team Leader from another free Unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader('Marco Santos');
    $unitA = ulUnit(['team_leader_id' => $tl->id]);
    $unitB = ulUnit();

    app(UnitTeamAssignmentService::class)->assignTeamLeader($unitB, $tl->id, $dispatcher);

    expect($unitA->fresh()->team_leader_id)->toBeNull();
    expect($unitB->fresh()->team_leader_id)->toBe($tl->id);
});

it('5. A borrowed Team Leader shows their original/home unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unitA = ulUnit(['team_leader_id' => $tl->id]);
    $unitB = ulUnit();

    app(UnitTeamAssignmentService::class)->assignTeamLeader($unitB, $tl->id, $dispatcher);

    $loan = UnitCrewLoan::where('to_unit_id', $unitB->id)->where('to_slot', 'team_leader')->whereNull('returned_at')->first();

    expect($loan)->not->toBeNull();
    expect($loan->from_unit_id)->toBe($unitA->id);
    expect($loan->person_user_id)->toBe($tl->id);
});

it('6. Return restores the Team Leader to their original Unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unitA = ulUnit(['team_leader_id' => $tl->id]);
    $unitB = ulUnit();

    $svc = app(UnitTeamAssignmentService::class);
    $svc->assignTeamLeader($unitB, $tl->id, $dispatcher);
    $svc->returnTeamLeader($unitB, $dispatcher);

    expect($unitA->fresh()->team_leader_id)->toBe($tl->id);
    expect($unitB->fresh()->team_leader_id)->toBeNull();
});

it('7. Driver Borrow and Return', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $unitA = ulUnit(['driver_name' => 'Juan Cruz']);
    $unitB = ulUnit();

    $svc = app(UnitTeamAssignmentService::class);
    $svc->assignSlotPerson($unitB, 'driver_1', $unitA, 'driver_1', $dispatcher);

    expect($unitA->fresh()->driver_name)->toBeNull();
    expect($unitB->fresh()->driver_name)->toBe('Juan Cruz');

    $loan = UnitCrewLoan::where('to_unit_id', $unitB->id)->where('to_slot', 'driver_1')->whereNull('returned_at')->firstOrFail();
    $svc->returnSlotPerson($loan, $dispatcher);

    expect($unitA->fresh()->driver_name)->toBe('Juan Cruz');
    expect($unitB->fresh()->driver_name)->toBeNull();
});

it('8. Crew Borrow and Return', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $unitA = ulUnit(['crew_member_1_name' => 'Carlo Reyes']);
    $unitB = ulUnit();

    $svc = app(UnitTeamAssignmentService::class);
    $svc->assignSlotPerson($unitB, 'crew_member_1', $unitA, 'crew_member_1', $dispatcher);

    expect($unitA->fresh()->crew_member_1_name)->toBeNull();
    expect($unitB->fresh()->crew_member_1_name)->toBe('Carlo Reyes');

    $loan = UnitCrewLoan::where('to_unit_id', $unitB->id)->where('to_slot', 'crew_member_1')->whereNull('returned_at')->firstOrFail();
    $svc->returnSlotPerson($loan, $dispatcher);

    expect($unitA->fresh()->crew_member_1_name)->toBe('Carlo Reyes');
});

it('9. Crew absence does not block availability', function () {
    ulRoles();
    $tl = ulTeamLeader();
    $unit = ulUnit(['team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz']);
    // No crew at all.

    $result = app(UnitAvailabilityService::class)->evaluate($unit->fresh());

    expect($result['available'])->toBeTrue();
});

it('10. Missing Team Leader blocks availability', function () {
    ulRoles();
    $unit = ulUnit(['driver_name' => 'Juan Cruz']);

    $result = app(UnitAvailabilityService::class)->evaluate($unit);

    expect($result['available'])->toBeFalse();
    expect($result['reasons'])->toContain('no_team_leader');
});

it('11. Missing Driver blocks availability', function () {
    ulRoles();
    $tl = ulTeamLeader();
    $unit = ulUnit(['team_leader_id' => $tl->id]);

    $result = app(UnitAvailabilityService::class)->evaluate($unit);

    expect($result['available'])->toBeFalse();
    expect($result['reasons'])->toContain('no_driver');
});

it('12. Team Leader Presence Offline does NOT block availability when Duty is Available', function () {
    ulRoles();
    $tl = ulTeamLeader();
    $unit = ulUnit(['team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz']);

    // Presence is deliberately never touched — TL has never pinged, so
    // presence is offline by default.
    $result = app(UnitAvailabilityService::class)->evaluate($unit);

    expect($result['presence'])->toBe('offline');
    expect($result['available'])->toBeTrue();
});

it('13. Duty Unavailable blocks availability', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unit = ulUnit(['team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz']);

    app(UnitTeamAssignmentService::class)->setTeamLeaderDuty($tl, 'unavailable', $dispatcher);

    $result = app(UnitAvailabilityService::class)->evaluate($unit->fresh());

    expect($result['available'])->toBeFalse();
    expect($result['reasons'])->toContain('team_leader_duty_unavailable');
});

it('14. Busy blocks borrowing', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unitA = ulUnit(['team_leader_id' => $tl->id]);
    $unitB = ulUnit();
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unitA->truck_type_id,
        'assigned_unit_id' => $unitA->id,
        'assigned_team_leader_id' => $tl->id,
        'age' => 30,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1875,
        'final_total' => 1875,
        'status' => 'on_the_way',
    ]);

    expect(fn () => app(UnitTeamAssignmentService::class)->assignTeamLeader($unitB, $tl->id, $dispatcher))
        ->toThrow(RuntimeException::class);

    expect($unitA->fresh()->team_leader_id)->toBe($tl->id);
});

it('15. Reserved Unit blocks team-breaking changes', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unitA = ulUnit(['team_leader_id' => $tl->id]);
    $unitB = ulUnit();
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unitA->truck_type_id,
        'selected_unit_id' => $unitA->id,
        'age' => 30,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1875,
        'final_total' => 1875,
        'status' => 'confirmed',
    ]);

    expect(fn () => app(UnitTeamAssignmentService::class)->assignTeamLeader($unitB, $tl->id, $dispatcher))
        ->toThrow(RuntimeException::class);

    expect($unitA->fresh()->team_leader_id)->toBe($tl->id);
});

it('16. Active Job blocks team-breaking changes (borrow-out)', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unitA = ulUnit(['team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz']);
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unitA->truck_type_id,
        'assigned_unit_id' => $unitA->id,
        'assigned_team_leader_id' => $tl->id,
        'age' => 30,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1875,
        'final_total' => 1875,
        'status' => 'in_progress',
    ]);

    expect(fn () => app(UnitTeamAssignmentService::class)->assignSlotPerson(ulUnit(), 'driver_1', $unitA, 'driver_1', $dispatcher))
        ->toThrow(RuntimeException::class);

    expect($unitA->fresh()->driver_name)->toBe('Juan Cruz');
});

it('17. Whole-team transfer between eligible Units', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unitA = ulUnit(['team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz', 'crew_member_1_name' => 'Carlo Reyes']);
    $unitB = ulUnit();

    app(UnitTeamAssignmentService::class)->transferTeam($unitA, $unitB, $dispatcher);

    $unitA->refresh();
    $unitB->refresh();

    expect($unitA->team_leader_id)->toBeNull();
    expect($unitA->driver_name)->toBeNull();
    expect($unitB->team_leader_id)->toBe($tl->id);
    expect($unitB->driver_name)->toBe('Juan Cruz');
    expect($unitB->crew_member_1_name)->toBe('Carlo Reyes');
});

it('18. Whole-team transfer blocked by Active Job', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unitA = ulUnit(['team_leader_id' => $tl->id]);
    $unitB = ulUnit();
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unitA->truck_type_id,
        'assigned_unit_id' => $unitA->id,
        'assigned_team_leader_id' => $tl->id,
        'age' => 30,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1875,
        'final_total' => 1875,
        'status' => 'assigned',
    ]);

    expect(fn () => app(UnitTeamAssignmentService::class)->transferTeam($unitA, $unitB, $dispatcher))
        ->toThrow(RuntimeException::class);
});

it('19. Whole-team transfer blocked by reservation', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unitA = ulUnit(['team_leader_id' => $tl->id]);
    $unitB = ulUnit();
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unitA->truck_type_id,
        'selected_unit_id' => $unitA->id,
        'age' => 30,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1875,
        'final_total' => 1875,
        'status' => 'confirmed',
    ]);

    expect(fn () => app(UnitTeamAssignmentService::class)->transferTeam($unitA, $unitB, $dispatcher))
        ->toThrow(RuntimeException::class);
});

it('20. Light/Medium/Heavy availability is counted separately', function () {
    ulRoles();
    $light = ulTruckType('light');
    $medium = ulTruckType('medium');

    $tl1 = ulTeamLeader();
    $tl2 = ulTeamLeader();

    ulUnit(['truck_type' => $light, 'team_leader_id' => $tl1->id, 'driver_name' => 'D1']);
    ulUnit(['truck_type' => $medium, 'team_leader_id' => $tl2->id, 'driver_name' => 'D2']);
    ulUnit(['truck_type' => $medium]); // no team -> not available, must not count

    $byClass = app(UnitAvailabilityService::class)->readyByClass();

    expect($byClass['light'] ?? 0)->toBe(1);
    expect($byClass['medium'] ?? 0)->toBe(1);
});

it('21. Book Now Truck Type availability uses the final availability formula', function () {
    ulRoles();
    $light = ulTruckType('light');
    $tl = ulTeamLeader();
    ulUnit(['truck_type' => $light, 'team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz']);

    $availability = app(\App\Services\BookingService::class)->dispatchAvailability();

    expect($availability['book_now_enabled'])->toBeTrue();
    expect($availability['ready_truck_type_ids'])->toContain($light->id);
});

it('22. Scheduled booking remains allowed when current availability is 0', function () {
    ulRoles();
    $light = ulTruckType('light');
    // No units at all for this truck type — book_now must be disabled...
    $availability = app(\App\Services\BookingService::class)->dispatchAvailability();
    expect($availability['book_now_enabled'])->toBeFalse();

    // ...but applyDispatchAvailabilityRules() must still allow a schedule request through.
    $data = app(\App\Services\BookingService::class)->applyDispatchAvailabilityRules([
        'service_type' => 'schedule',
        'truck_type_id' => $light->id,
    ]);

    expect($data['service_type'])->toBe('schedule');
});

it('23. Team Leader mobile ping does not overwrite Duty', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    app(UnitTeamAssignmentService::class)->setTeamLeaderDuty($tl, 'unavailable', $dispatcher);

    app(\App\Services\TeamLeaderAvailabilityService::class)->markOnline($tl->fresh());

    expect($tl->fresh()->dutyStatus())->toBe('unavailable');
});

it('24. Team Leader mobile going offline does not release an active job/unit', function () {
    ulRoles();
    $tl = ulTeamLeader();
    $unit = ulUnit(['team_leader_id' => $tl->id]);
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $tl->id,
        'age' => 30,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1875,
        'final_total' => 1875,
        'status' => 'on_the_way',
    ]);

    // Passive staleness (no explicit offline call) — the realistic
    // "phone died" scenario. Unit/team must remain exactly as committed.
    expect($unit->fresh()->team_leader_id)->toBe($tl->id);
    expect(app(\App\Services\TeamLeaderAvailabilityService::class)->isOnline($tl->fresh()))->toBeFalse();
});

it('25. Existing dispatch transaction still rejects a stale/ineligible Unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $unit = ulUnit(['status' => 'maintenance']);
    $customer = ulCustomer();

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'age' => 30,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1875,
        'final_total' => 1875,
        'status' => 'confirmed',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.booking.assign', $booking), [
            'action' => 'accept',
            'assigned_unit_id' => $unit->id,
            'distance_km' => 5,
            'distance_fee' => 0,
        ])
        ->assertStatus(422);
});

// ===========================================================================
// Page-render (HTML) scenarios A-I
// ===========================================================================

it('34. [A] Available unit page render: Duty Available, Workload Free, Availability Available, Transfer Team shown', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader('Bea Fernandez');
    ulUnit(['name' => 'JARZ Alpha', 'team_leader_id' => $tl->id, 'driver_name' => 'Paolo Cruz']);

    $html = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->getContent();

    expect($html)->toContain('Bea Fernandez')
        ->and($html)->toContain('Paolo Cruz')
        ->and($html)->toContain('Available') // Duty + Availability values
        ->and($html)->toContain('Free') // Workload
        ->and($html)->toContain('Transfer team');
});

it('35. [B] Offline TL but operationally Available: Presence Offline, Duty Available, Availability Available all render', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader('Marco Offline');
    $unit = ulUnit(['name' => 'JARZ Bravo', 'team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz']);

    $result = app(UnitAvailabilityService::class)->evaluate($unit->fresh());
    expect($result['presence'])->toBe('offline');
    expect($result['available'])->toBeTrue();

    $html = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->getContent();

    expect($html)->toContain('JARZ Bravo')
        ->and($html)->toContain('Marco Offline')
        ->and($html)->toContain('Offline');
});

it('36. [C] Duty Unavailable renders Availability Not Available with the reason', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader('Carlo Duty Off');
    $unit = ulUnit(['name' => 'JARZ Charlie', 'team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz']);
    app(UnitTeamAssignmentService::class)->setTeamLeaderDuty($tl, 'unavailable', $dispatcher);

    $html = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->getContent();

    expect($html)->toContain('JARZ Charlie')
        ->and($html)->toContain('Not Available')
        ->and($html)->toContain('Unavailable');
});

it('37. [D] Empty Team Leader slot renders "Not assigned" and an Assign action', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    ulUnit(['name' => 'JARZ Delta']);

    $html = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->getContent();

    expect($html)->toContain('Team Leader')
        ->and($html)->toContain('Not assigned')
        ->and($html)->toContain('data-role="team_leader"');
});

it('38. [D] Empty Driver slot renders "Not assigned" and an Assign action', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    ulUnit(['name' => 'JARZ Echo', 'team_leader_id' => $tl->id]);

    $html = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->getContent();

    expect($html)->toContain('Driver')
        ->and($html)->toContain('Not assigned')
        ->and($html)->toContain('data-role="driver"');
});

it('39. [D] Empty Crew 1 and Crew 2 render separately and crew absence does not block Availability', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unit = ulUnit(['name' => 'JARZ Foxtrot', 'team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz']);

    $result = app(UnitAvailabilityService::class)->evaluate($unit->fresh());
    expect($result['available'])->toBeTrue();

    $html = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->getContent();

    expect($html)->toContain('Crew 1')
        ->and($html)->toContain('Crew 2')
        ->and(substr_count($html, 'Not assigned'))->toBeGreaterThanOrEqual(2)
        ->and($html)->toContain('data-role="crew"')
        ->and($html)->toContain('JARZ Foxtrot');
});

it('40. [E] Reserved unit hides Assign/Return/Transfer and shows View Booking + booking reference', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unit = ulUnit(['name' => 'JARZ Golf', 'team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz']);
    $customer = ulCustomer();

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'selected_unit_id' => $unit->id,
        'age' => 30,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1875,
        'final_total' => 1875,
        'status' => 'confirmed',
    ]);

    $html = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->getContent();

    expect($html)->toContain('JARZ Golf')
        ->and($html)->toContain($booking->booking_code)
        ->and($html)->toContain('Reserved')
        ->and($html)->toContain('View booking')
        ->and($html)->toContain('Not Available')
        ->and($html)->not->toContain('data-action="open-assign"')
        ->and($html)->not->toContain('data-action="return-team-leader"')
        ->and($html)->not->toContain('data-action="return-slot"')
        ->and($html)->not->toContain('data-action="open-transfer"');
});

it('41. [F] Active-Job/Busy unit shows Workload Busy, View Job, booking code, and hides Assign/Return/Transfer', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unit = ulUnit(['name' => 'JARZ Hotel', 'team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz']);
    $customer = ulCustomer();

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $tl->id,
        'age' => 30,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1875,
        'final_total' => 1875,
        'status' => 'on_the_way',
    ]);

    $html = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->getContent();

    expect($html)->toContain('JARZ Hotel')
        ->and($html)->toContain($booking->booking_code)
        ->and($html)->toContain('View job')
        ->and($html)->toContain('Busy')
        ->and($html)->toContain('Not Available')
        ->and($html)->not->toContain('data-action="open-assign"')
        ->and($html)->not->toContain('data-action="return-team-leader"')
        ->and($html)->not->toContain('data-action="return-slot"')
        ->and($html)->not->toContain('data-action="open-transfer"');
});

it('42. [G] Borrowed Team Leader displays "Borrowed from" the correct source Unit with a Return action', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader('Marco Borrowed');
    $unitA = ulUnit(['name' => 'JARZ India', 'team_leader_id' => $tl->id]);
    $unitB = ulUnit(['name' => 'JARZ Juliet']);

    app(UnitTeamAssignmentService::class)->assignTeamLeader($unitB, $tl->id, $dispatcher);

    $html = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->getContent();

    expect($html)->toContain('Marco Borrowed')
        ->and($html)->toContain('Borrowed from JARZ India')
        ->and($html)->toContain('data-action="return-team-leader"');
});

it('43. [G] Borrowed Driver displays "Borrowed from" the correct source Unit with a Return action', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $unitA = ulUnit(['name' => 'JARZ Kilo', 'driver_name' => 'Edwin Flores']);
    $unitB = ulUnit(['name' => 'JARZ Lima']);

    app(UnitTeamAssignmentService::class)->assignSlotPerson($unitB, 'driver_1', $unitA, 'driver_1', $dispatcher);

    $html = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->getContent();

    expect($html)->toContain('Edwin Flores')
        ->and($html)->toContain('Borrowed from JARZ Kilo')
        ->and($html)->toContain('data-action="return-slot"');
});

it('44. [H] Truck Type summary strip is removed from the page, but the per-Truck-Type availability formula is unchanged', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $medium = ulTruckType('medium');
    $heavy = ulTruckType('heavy');
    // Deliberately no light truck type/unit at all.
    $tlMedium = ulTeamLeader();
    $tlHeavy = ulTeamLeader();
    ulUnit(['truck_type' => $medium, 'team_leader_id' => $tlMedium->id, 'driver_name' => 'D1']);
    ulUnit(['truck_type' => $heavy, 'team_leader_id' => $tlHeavy->id, 'driver_name' => 'D2']);

    $html = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->getContent();

    // The summary strip UI is gone — Truck Type is already visible per row
    // and has its own filter, so this uppercase strip no longer renders.
    expect($html)->not->toContain('ul-summary-item')
        ->and($html)->not->toContain('LIGHT DUTY')
        ->and($html)->not->toContain('MEDIUM DUTY')
        ->and($html)->not->toContain('HEAVY DUTY');

    // The underlying per-Truck-Type availability formula itself is
    // untouched — only its display on this page was removed.
    $readyByClass = app(UnitAvailabilityService::class)->readyByClass();
    expect($readyByClass['light'] ?? 0)->toBe(0);
    expect($readyByClass['medium'] ?? 0)->toBe(1);
    expect($readyByClass['heavy'] ?? 0)->toBe(1);
});

it('45. [I] Filter and search controls are present with their existing JS hooks', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    ulUnit();

    $html = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->getContent();

    expect($html)->toContain('id="ulAvailability"')
        ->and($html)->toContain('id="ulPresence"')
        ->and($html)->toContain('id="ulTruckType"')
        ->and($html)->toContain('id="ulSearch"')
        ->and($html)->toContain('Search unit or personnel');
});

// ===========================================================================
// HTTP action endpoints (success + reject)
// ===========================================================================

it('46. HTTP Assign Team Leader succeeds on a free destination Unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unit = ulUnit();

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.assign-team-leader', $unit), ['team_leader_id' => $tl->id])
        ->assertOk()
        ->assertJson(['success' => true]);

    expect($unit->fresh()->team_leader_id)->toBe($tl->id);
});

it('47. HTTP Assign Team Leader is rejected server-side when the destination Unit is Reserved', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unit = ulUnit();
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'selected_unit_id' => $unit->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'confirmed',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.assign-team-leader', $unit), ['team_leader_id' => $tl->id])
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($unit->fresh()->team_leader_id)->toBeNull();
});

it('48. HTTP Assign Driver (borrow via assign-slot) succeeds on free source and destination', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $sourceUnit = ulUnit(['driver_name' => 'Juan Cruz']);
    $destUnit = ulUnit();

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.assign-slot', $destUnit), [
            'to_slot' => 'driver_1',
            'source_unit_id' => $sourceUnit->id,
            'from_slot' => 'driver_1',
        ])
        ->assertOk()
        ->assertJson(['success' => true]);

    expect($destUnit->fresh()->driver_name)->toBe('Juan Cruz');
    expect($sourceUnit->fresh()->driver_name)->toBeNull();
});

it('49. HTTP Assign Driver is rejected server-side when the destination Unit has an Active Job', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $sourceUnit = ulUnit(['driver_name' => 'Juan Cruz']);
    $busyUnit = ulUnit();
    $busyTl = ulTeamLeader();
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $busyUnit->truck_type_id,
        'assigned_unit_id' => $busyUnit->id,
        'assigned_team_leader_id' => $busyTl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'in_progress',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.assign-slot', $busyUnit), [
            'to_slot' => 'driver_1',
            'source_unit_id' => $sourceUnit->id,
            'from_slot' => 'driver_1',
        ])
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($busyUnit->fresh()->driver_name)->toBeNull();
    expect($sourceUnit->fresh()->driver_name)->toBe('Juan Cruz');
});

it('50. HTTP Assign Crew succeeds on free source and destination', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $sourceUnit = ulUnit(['crew_member_1_name' => 'Carlo Reyes']);
    $destUnit = ulUnit();

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.assign-slot', $destUnit), [
            'to_slot' => 'crew_member_1',
            'source_unit_id' => $sourceUnit->id,
            'from_slot' => 'crew_member_1',
        ])
        ->assertOk()
        ->assertJson(['success' => true]);

    expect($destUnit->fresh()->crew_member_1_name)->toBe('Carlo Reyes');
});

it('51. HTTP Borrow Crew is rejected server-side when the SOURCE Unit is Reserved', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $sourceUnit = ulUnit(['crew_member_1_name' => 'Carlo Reyes']);
    $destUnit = ulUnit();
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $sourceUnit->truck_type_id,
        'selected_unit_id' => $sourceUnit->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'confirmed',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.assign-slot', $destUnit), [
            'to_slot' => 'crew_member_1',
            'source_unit_id' => $sourceUnit->id,
            'from_slot' => 'crew_member_1',
        ])
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($sourceUnit->fresh()->crew_member_1_name)->toBe('Carlo Reyes');
    expect($destUnit->fresh()->crew_member_1_name)->toBeNull();
});

it('52. HTTP Return Team Leader succeeds and restores the home Unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unitA = ulUnit(['team_leader_id' => $tl->id]);
    $unitB = ulUnit();
    app(UnitTeamAssignmentService::class)->assignTeamLeader($unitB, $tl->id, $dispatcher);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.return-team-leader', $unitB))
        ->assertOk()
        ->assertJson(['success' => true]);

    expect($unitA->fresh()->team_leader_id)->toBe($tl->id);
    expect($unitB->fresh()->team_leader_id)->toBeNull();
});

it('53. HTTP Return Team Leader is rejected server-side when the home Unit is now Busy', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unitA = ulUnit(['team_leader_id' => $tl->id]);
    $unitB = ulUnit();
    app(UnitTeamAssignmentService::class)->assignTeamLeader($unitB, $tl->id, $dispatcher);

    $busyTl = ulTeamLeader();
    $customer = ulCustomer();
    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unitA->truck_type_id,
        'assigned_unit_id' => $unitA->id,
        'assigned_team_leader_id' => $busyTl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'in_progress',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.return-team-leader', $unitB))
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($unitB->fresh()->team_leader_id)->toBe($tl->id);
});

it('54. HTTP Return Driver is rejected server-side when the home Unit has become Reserved', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $unitA = ulUnit(['driver_name' => 'Juan Cruz']);
    $unitB = ulUnit();
    app(UnitTeamAssignmentService::class)->assignSlotPerson($unitB, 'driver_1', $unitA, 'driver_1', $dispatcher);
    $loan = UnitCrewLoan::where('to_unit_id', $unitB->id)->where('to_slot', 'driver_1')->whereNull('returned_at')->firstOrFail();

    $customer = ulCustomer();
    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unitA->truck_type_id,
        'selected_unit_id' => $unitA->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'confirmed',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.loans.return', $loan))
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($unitB->fresh()->driver_name)->toBe('Juan Cruz');
    expect($unitA->fresh()->driver_name)->toBeNull();
});

it('55. HTTP Change Duty updates the Team Leader\'s duty status', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.team-leaders.duty', $tl), ['status' => 'unavailable'])
        ->assertOk()
        ->assertJson(['success' => true]);

    expect($tl->fresh()->dutyStatus())->toBe('unavailable');
});

it('56. HTTP Transfer Team succeeds between two free/eligible Units', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unitA = ulUnit(['team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz']);
    $unitB = ulUnit();

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.transfer-team', $unitA), ['target_unit_id' => $unitB->id])
        ->assertOk()
        ->assertJson(['success' => true]);

    expect($unitA->fresh()->team_leader_id)->toBeNull();
    expect($unitB->fresh()->team_leader_id)->toBe($tl->id);
    expect($unitB->fresh()->driver_name)->toBe('Juan Cruz');
});

it('57. HTTP Transfer Team is rejected when the destination Unit is Reserved (source free)', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unitA = ulUnit(['team_leader_id' => $tl->id]);
    $unitB = ulUnit();
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unitB->truck_type_id,
        'selected_unit_id' => $unitB->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'confirmed',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.transfer-team', $unitA), ['target_unit_id' => $unitB->id])
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($unitA->fresh()->team_leader_id)->toBe($tl->id);
});

it('58. HTTP Transfer Team is rejected when the source Unit has an Active Job (destination free)', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unitA = ulUnit(['team_leader_id' => $tl->id]);
    $unitB = ulUnit();
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unitA->truck_type_id,
        'assigned_unit_id' => $unitA->id,
        'assigned_team_leader_id' => $tl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'assigned',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.transfer-team', $unitA), ['target_unit_id' => $unitB->id])
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($unitB->fresh()->team_leader_id)->toBeNull();
});

// ===========================================================================
// Presence regression via HTTP ping + Carbon-controlled time
// ===========================================================================

it('59. HTTP ping marks Presence Online but never touches Dispatcher-set Duty', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = User::factory()->create(['role_id' => 3, 'must_change_password' => false]);
    app(UnitTeamAssignmentService::class)->setTeamLeaderDuty($tl, 'unavailable', $dispatcher);

    Sanctum::actingAs($tl, ['*']);
    $this->postJson('/api/v1/team-leader/presence/ping')
        ->assertOk()
        ->assertJson(['success' => true, 'presence' => 'online']);

    expect(app(\App\Services\TeamLeaderAvailabilityService::class)->isOnline($tl->fresh()))->toBeTrue();
    expect($tl->fresh()->dutyStatus())->toBe('unavailable');
});

it('60. Passive presence staleness (Carbon-controlled) does not release the active job/unit or change Duty', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unit = ulUnit(['team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz']);
    app(UnitTeamAssignmentService::class)->setTeamLeaderDuty($tl, 'available', $dispatcher);
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $tl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'on_the_way',
    ]);

    app(\App\Services\TeamLeaderAvailabilityService::class)->markOnline($tl->fresh());
    expect(app(\App\Services\TeamLeaderAvailabilityService::class)->isOnline($tl->fresh()))->toBeTrue();

    // Advance test time well past the presence window without any further
    // ping — nothing in the system polls for staleness to release anything.
    Carbon::setTestNow(now()->addMinutes(10));

    try {
        expect(app(\App\Services\TeamLeaderAvailabilityService::class)->isOnline($tl->fresh()))->toBeFalse();
        expect($unit->fresh()->team_leader_id)->toBe($tl->id);
        expect($tl->fresh()->dutyStatus())->toBe('available');
        expect(Booking::where('assigned_unit_id', $unit->id)->first()->status)->toBe('on_the_way');
    } finally {
        Carbon::setTestNow();
    }
});

// ===========================================================================
// Customer Book Now integration
// ===========================================================================

it('61. Book Now: 0 final-available Light + 1 final-available Medium reflects exactly in dispatchAvailability()', function () {
    ulRoles();
    $light = ulTruckType('light');
    $medium = ulTruckType('medium');
    // Light has a unit but no team -> not finally available, must read 0.
    ulUnit(['truck_type' => $light]);
    $tl = ulTeamLeader();
    ulUnit(['truck_type' => $medium, 'team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz']);

    $availability = app(\App\Services\BookingService::class)->dispatchAvailability();

    expect($availability['ready_truck_type_ids'])->not->toContain($light->id);
    expect($availability['ready_truck_type_ids'])->toContain($medium->id);

    $readyByClass = app(UnitAvailabilityService::class)->readyByClass();
    expect($readyByClass['light'] ?? 0)->toBe(0);
    expect($readyByClass['medium'] ?? 0)->toBe(1);
});

it('62. Book Now: TL Presence Offline does not reduce the ready count when Duty/Workload/team requirements pass', function () {
    ulRoles();
    $light = ulTruckType('light');
    $tl = ulTeamLeader();
    ulUnit(['truck_type' => $light, 'team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz']);
    // TL has never pinged -> presence is offline by construction.

    expect(app(UnitAvailabilityService::class)->presence($tl->fresh()))->toBe('offline');

    $availability = app(\App\Services\BookingService::class)->dispatchAvailability();

    expect($availability['book_now_enabled'])->toBeTrue();
    expect($availability['ready_truck_type_ids'])->toContain($light->id);
});

it('63. Scheduled service_type remains allowed even when a truck class has 0 final-available units', function () {
    ulRoles();
    $light = ulTruckType('light');
    // No units at all for this truck type.

    $data = app(\App\Services\BookingService::class)->applyDispatchAvailabilityRules([
        'service_type' => 'schedule',
        'truck_type_id' => $light->id,
    ]);

    expect($data['service_type'])->toBe('schedule');
});

it('26. Cannot assign Team Leader into empty slot on a Reserved Unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $reservedUnit = ulUnit(); // empty TL slot
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $reservedUnit->truck_type_id,
        'selected_unit_id' => $reservedUnit->id,
        'age' => 30,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1875,
        'final_total' => 1875,
        'status' => 'confirmed',
    ]);

    expect(fn () => app(UnitTeamAssignmentService::class)->assignTeamLeader($reservedUnit, $tl->id, $dispatcher))
        ->toThrow(RuntimeException::class);

    expect($reservedUnit->fresh()->team_leader_id)->toBeNull();
});

it('27. Cannot assign Driver into empty slot on a Reserved Unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $sourceUnit = ulUnit(['driver_name' => 'Juan Cruz']);
    $reservedUnit = ulUnit(); // empty driver slot
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $reservedUnit->truck_type_id,
        'selected_unit_id' => $reservedUnit->id,
        'age' => 30,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1875,
        'final_total' => 1875,
        'status' => 'confirmed',
    ]);

    expect(fn () => app(UnitTeamAssignmentService::class)->assignSlotPerson($reservedUnit, 'driver_1', $sourceUnit, 'driver_1', $dispatcher))
        ->toThrow(RuntimeException::class);

    expect($reservedUnit->fresh()->driver_name)->toBeNull();
    expect($sourceUnit->fresh()->driver_name)->toBe('Juan Cruz');
});

it('28. Cannot assign Crew into empty slot on a Reserved Unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $sourceUnit = ulUnit(['crew_member_1_name' => 'Carlo Reyes']);
    $reservedUnit = ulUnit(); // empty crew slot
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $reservedUnit->truck_type_id,
        'selected_unit_id' => $reservedUnit->id,
        'age' => 30,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1875,
        'final_total' => 1875,
        'status' => 'confirmed',
    ]);

    expect(fn () => app(UnitTeamAssignmentService::class)->assignSlotPerson($reservedUnit, 'crew_member_1', $sourceUnit, 'crew_member_1', $dispatcher))
        ->toThrow(RuntimeException::class);

    expect($reservedUnit->fresh()->crew_member_1_name)->toBeNull();
    expect($sourceUnit->fresh()->crew_member_1_name)->toBe('Carlo Reyes');
});

it('29. Cannot assign Team Leader into empty slot on an Active-Job Unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $busyUnit = ulUnit(); // empty TL slot
    $busyTl = ulTeamLeader();
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $busyUnit->truck_type_id,
        'assigned_unit_id' => $busyUnit->id,
        'assigned_team_leader_id' => $busyTl->id,
        'age' => 30,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1875,
        'final_total' => 1875,
        'status' => 'in_progress',
    ]);

    expect(fn () => app(UnitTeamAssignmentService::class)->assignTeamLeader($busyUnit, $tl->id, $dispatcher))
        ->toThrow(RuntimeException::class);

    expect($busyUnit->fresh()->team_leader_id)->toBeNull();
});

it('30. Cannot assign Driver into empty slot on an Active-Job Unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $sourceUnit = ulUnit(['driver_name' => 'Juan Cruz']);
    $busyUnit = ulUnit(); // empty driver slot
    $busyTl = ulTeamLeader();
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $busyUnit->truck_type_id,
        'assigned_unit_id' => $busyUnit->id,
        'assigned_team_leader_id' => $busyTl->id,
        'age' => 30,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1875,
        'final_total' => 1875,
        'status' => 'in_progress',
    ]);

    expect(fn () => app(UnitTeamAssignmentService::class)->assignSlotPerson($busyUnit, 'driver_1', $sourceUnit, 'driver_1', $dispatcher))
        ->toThrow(RuntimeException::class);

    expect($busyUnit->fresh()->driver_name)->toBeNull();
    expect($sourceUnit->fresh()->driver_name)->toBe('Juan Cruz');
});

it('31. Cannot assign Crew into empty slot on an Active-Job Unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $sourceUnit = ulUnit(['crew_member_1_name' => 'Carlo Reyes']);
    $busyUnit = ulUnit(); // empty crew slot
    $busyTl = ulTeamLeader();
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $busyUnit->truck_type_id,
        'assigned_unit_id' => $busyUnit->id,
        'assigned_team_leader_id' => $busyTl->id,
        'age' => 30,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1875,
        'final_total' => 1875,
        'status' => 'in_progress',
    ]);

    expect(fn () => app(UnitTeamAssignmentService::class)->assignSlotPerson($busyUnit, 'crew_member_1', $sourceUnit, 'crew_member_1', $dispatcher))
        ->toThrow(RuntimeException::class);

    expect($busyUnit->fresh()->crew_member_1_name)->toBeNull();
    expect($sourceUnit->fresh()->crew_member_1_name)->toBe('Carlo Reyes');
});

it('32. A free/unreserved Unit can still Assign Team Leader, Driver, and Crew normally', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $driverSource = ulUnit(['driver_name' => 'Juan Cruz']);
    $crewSource = ulUnit(['crew_member_1_name' => 'Carlo Reyes']);
    $freeUnit = ulUnit();

    $svc = app(UnitTeamAssignmentService::class);
    $svc->assignTeamLeader($freeUnit, $tl->id, $dispatcher);
    $svc->assignSlotPerson($freeUnit, 'driver_1', $driverSource, 'driver_1', $dispatcher);
    $svc->assignSlotPerson($freeUnit, 'crew_member_1', $crewSource, 'crew_member_1', $dispatcher);

    $freeUnit->refresh();
    expect($freeUnit->team_leader_id)->toBe($tl->id);
    expect($freeUnit->driver_name)->toBe('Juan Cruz');
    expect($freeUnit->crew_member_1_name)->toBe('Carlo Reyes');
});

it('33. Existing Borrow/Return/Transfer safety remains passing after the reserved/active-job guard change', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unitA = ulUnit(['team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz', 'crew_member_1_name' => 'Carlo Reyes']);
    $unitB = ulUnit();
    $unitC = ulUnit();

    $svc = app(UnitTeamAssignmentService::class);

    // Borrow TL away, then return it home.
    $svc->assignTeamLeader($unitB, $tl->id, $dispatcher);
    expect($unitA->fresh()->team_leader_id)->toBeNull();
    $svc->returnTeamLeader($unitB, $dispatcher);
    expect($unitA->fresh()->team_leader_id)->toBe($tl->id);

    // Borrow driver away, then return it home.
    $svc->assignSlotPerson($unitB, 'driver_1', $unitA, 'driver_1', $dispatcher);
    expect($unitA->fresh()->driver_name)->toBeNull();
    $loan = UnitCrewLoan::where('to_unit_id', $unitB->id)->where('to_slot', 'driver_1')->whereNull('returned_at')->firstOrFail();
    $svc->returnSlotPerson($loan, $dispatcher);
    expect($unitA->fresh()->driver_name)->toBe('Juan Cruz');

    // Whole-team transfer still works on eligible, free units.
    $svc->transferTeam($unitA, $unitC, $dispatcher);
    expect($unitA->fresh()->team_leader_id)->toBeNull();
    expect($unitC->fresh()->team_leader_id)->toBe($tl->id);
    expect($unitC->fresh()->crew_member_1_name)->toBe('Carlo Reyes');
});

// ===========================================================================
// Operational locking — Busy (Active Job) personnel must not be moved or
// have Duty toggled through Units & Leaders. Reuses the same canonical
// busy definition everywhere (UnitTeamAssignmentService::isBusy() /
// UnitAvailabilityService's active_booking).
// ===========================================================================

it('64. Busy Team Leader cannot change Duty Available -> Unavailable', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unit = ulUnit(['team_leader_id' => $tl->id]);
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $tl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'on_the_way',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.team-leaders.duty', $tl), ['status' => 'unavailable'])
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($tl->fresh()->dutyStatus())->toBe('available');
});

it('65. Busy Team Leader cannot change Duty Unavailable -> Available', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unit = ulUnit(['team_leader_id' => $tl->id]);
    // Set to unavailable first, while still free — this must succeed.
    app(UnitTeamAssignmentService::class)->setTeamLeaderDuty($tl, 'unavailable', $dispatcher);
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $tl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'in_progress',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.team-leaders.duty', $tl), ['status' => 'available'])
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($tl->fresh()->dutyStatus())->toBe('unavailable');
});

it('66. Free Team Leader can still change Duty normally', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    ulUnit(['team_leader_id' => $tl->id]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.team-leaders.duty', $tl), ['status' => 'unavailable'])
        ->assertOk()
        ->assertJson(['success' => true]);

    expect($tl->fresh()->dutyStatus())->toBe('unavailable');
});

it('67. Busy Team Leader cannot be borrowed into another Unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unitA = ulUnit(['team_leader_id' => $tl->id]);
    $unitB = ulUnit();
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unitA->truck_type_id,
        'assigned_unit_id' => $unitA->id,
        'assigned_team_leader_id' => $tl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'accepted',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.assign-team-leader', $unitB), ['team_leader_id' => $tl->id])
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($unitA->fresh()->team_leader_id)->toBe($tl->id);
    expect($unitB->fresh()->team_leader_id)->toBeNull();
});

it('68. Busy Team Leader cannot be returned away from the Active Job', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unitA = ulUnit(['team_leader_id' => $tl->id]);
    $unitB = ulUnit();
    app(UnitTeamAssignmentService::class)->assignTeamLeader($unitB, $tl->id, $dispatcher);

    // The TL becomes busy only after the borrow — on the unit they now sit on.
    $customer = ulCustomer();
    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unitB->truck_type_id,
        'assigned_unit_id' => $unitB->id,
        'assigned_team_leader_id' => $tl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'loading_vehicle',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.return-team-leader', $unitB))
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($unitB->fresh()->team_leader_id)->toBe($tl->id);
    expect($unitA->fresh()->team_leader_id)->toBeNull();
});

it('69. Busy Driver cannot be borrowed out of their Unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $sourceUnit = ulUnit(['driver_name' => 'Juan Cruz']);
    $destUnit = ulUnit();
    $busyTl = ulTeamLeader();
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $sourceUnit->truck_type_id,
        'assigned_unit_id' => $sourceUnit->id,
        'assigned_team_leader_id' => $busyTl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'arrived_pickup',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.assign-slot', $destUnit), [
            'to_slot' => 'driver_1',
            'source_unit_id' => $sourceUnit->id,
            'from_slot' => 'driver_1',
        ])
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($sourceUnit->fresh()->driver_name)->toBe('Juan Cruz');
    expect($destUnit->fresh()->driver_name)->toBeNull();
});

it('70. Busy Driver cannot be returned to their home Unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $sourceUnit = ulUnit(['driver_name' => 'Juan Cruz']);
    $destUnit = ulUnit();
    app(UnitTeamAssignmentService::class)->assignSlotPerson($destUnit, 'driver_1', $sourceUnit, 'driver_1', $dispatcher);
    $loan = UnitCrewLoan::where('to_unit_id', $destUnit->id)->where('to_slot', 'driver_1')->whereNull('returned_at')->firstOrFail();

    // The unit the driver is currently on (destUnit) becomes busy.
    $busyTl = ulTeamLeader();
    $customer = ulCustomer();
    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $destUnit->truck_type_id,
        'assigned_unit_id' => $destUnit->id,
        'assigned_team_leader_id' => $busyTl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'arrived_dropoff',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.loans.return', $loan))
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($destUnit->fresh()->driver_name)->toBe('Juan Cruz');
    expect($sourceUnit->fresh()->driver_name)->toBeNull();
    expect($loan->fresh()->returned_at)->toBeNull();
});

it('71. Busy Crew 1 cannot be borrowed out of their Unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $sourceUnit = ulUnit(['crew_member_1_name' => 'Carlo Reyes']);
    $destUnit = ulUnit();
    $busyTl = ulTeamLeader();
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $sourceUnit->truck_type_id,
        'assigned_unit_id' => $sourceUnit->id,
        'assigned_team_leader_id' => $busyTl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'on_job',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.assign-slot', $destUnit), [
            'to_slot' => 'crew_member_1',
            'source_unit_id' => $sourceUnit->id,
            'from_slot' => 'crew_member_1',
        ])
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($sourceUnit->fresh()->crew_member_1_name)->toBe('Carlo Reyes');
});

it('72. Busy Crew 1 cannot be returned to their home Unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $sourceUnit = ulUnit(['crew_member_1_name' => 'Carlo Reyes']);
    $destUnit = ulUnit();
    app(UnitTeamAssignmentService::class)->assignSlotPerson($destUnit, 'crew_member_1', $sourceUnit, 'crew_member_1', $dispatcher);
    $loan = UnitCrewLoan::where('to_unit_id', $destUnit->id)->where('to_slot', 'crew_member_1')->whereNull('returned_at')->firstOrFail();

    $busyTl = ulTeamLeader();
    $customer = ulCustomer();
    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $destUnit->truck_type_id,
        'assigned_unit_id' => $destUnit->id,
        'assigned_team_leader_id' => $busyTl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'assigned',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.loans.return', $loan))
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($destUnit->fresh()->crew_member_1_name)->toBe('Carlo Reyes');
    expect($loan->fresh()->returned_at)->toBeNull();
});

it('73. Busy Crew 2 cannot be borrowed out of their Unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $sourceUnit = ulUnit(['crew_member_2_name' => 'Nico Santos']);
    $destUnit = ulUnit();
    $busyTl = ulTeamLeader();
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $sourceUnit->truck_type_id,
        'assigned_unit_id' => $sourceUnit->id,
        'assigned_team_leader_id' => $busyTl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'waiting_verification',
        'payment_submitted_at' => null,
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.assign-slot', $destUnit), [
            'to_slot' => 'crew_member_2',
            'source_unit_id' => $sourceUnit->id,
            'from_slot' => 'crew_member_2',
        ])
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($sourceUnit->fresh()->crew_member_2_name)->toBe('Nico Santos');
});

it('74. Busy Crew 2 cannot be returned to their home Unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $sourceUnit = ulUnit(['crew_member_2_name' => 'Nico Santos']);
    $destUnit = ulUnit();
    app(UnitTeamAssignmentService::class)->assignSlotPerson($destUnit, 'crew_member_2', $sourceUnit, 'crew_member_2', $dispatcher);
    $loan = UnitCrewLoan::where('to_unit_id', $destUnit->id)->where('to_slot', 'crew_member_2')->whereNull('returned_at')->firstOrFail();

    $busyTl = ulTeamLeader();
    $customer = ulCustomer();
    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $destUnit->truck_type_id,
        'assigned_unit_id' => $destUnit->id,
        'assigned_team_leader_id' => $busyTl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'on_the_way',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.loans.return', $loan))
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($destUnit->fresh()->crew_member_2_name)->toBe('Nico Santos');
    expect($loan->fresh()->returned_at)->toBeNull();
});

it('75. Whole-team transfer fails when the source Team Leader is Busy via a job not tied to the source Unit', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $sourceUnit = ulUnit(['team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz']);
    $destUnit = ulUnit();
    $customer = ulCustomer();

    // Busy via assigned_team_leader_id only — assigned_unit_id deliberately
    // left blank, isolating the leader-id fallback that
    // UnitAvailabilityService::evaluate() uses (the same fallback every
    // other guard in this service already relies on).
    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $sourceUnit->truck_type_id,
        'assigned_team_leader_id' => $tl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'in_progress',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.transfer-team', $sourceUnit), ['target_unit_id' => $destUnit->id])
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($sourceUnit->fresh()->team_leader_id)->toBe($tl->id);
    expect($destUnit->fresh()->team_leader_id)->toBeNull();
});

it('76. Whole-team transfer fails when the destination Unit has an Active Job (source free)', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $sourceUnit = ulUnit(['team_leader_id' => $tl->id]);
    $destUnit = ulUnit();
    $busyTl = ulTeamLeader();
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $destUnit->truck_type_id,
        'assigned_unit_id' => $destUnit->id,
        'assigned_team_leader_id' => $busyTl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'in_progress',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.transfer-team', $sourceUnit), ['target_unit_id' => $destUnit->id])
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($sourceUnit->fresh()->team_leader_id)->toBe($tl->id);
});

it('77. Free/unreserved personnel (TL, Driver, Crew) still Assign/Borrow/Return normally', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $tlHomeUnit = ulUnit(['team_leader_id' => $tl->id]);
    $driverSource = ulUnit(['driver_name' => 'Juan Cruz']);
    $crewSource = ulUnit(['crew_member_1_name' => 'Carlo Reyes']);
    $freeUnit = ulUnit();

    // Borrow (not a fresh assign) so returnTeamLeader() below has an actual
    // loan record to restore — matches how the drawer's "Return" only ever
    // appears for a borrowed person in the first place.
    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.assign-team-leader', $freeUnit), ['team_leader_id' => $tl->id])
        ->assertOk()->assertJson(['success' => true]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.assign-slot', $freeUnit), [
            'to_slot' => 'driver_1', 'source_unit_id' => $driverSource->id, 'from_slot' => 'driver_1',
        ])->assertOk()->assertJson(['success' => true]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.assign-slot', $freeUnit), [
            'to_slot' => 'crew_member_1', 'source_unit_id' => $crewSource->id, 'from_slot' => 'crew_member_1',
        ])->assertOk()->assertJson(['success' => true]);

    $driverLoan = UnitCrewLoan::where('to_unit_id', $freeUnit->id)->where('to_slot', 'driver_1')->whereNull('returned_at')->firstOrFail();
    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.loans.return', $driverLoan))
        ->assertOk()->assertJson(['success' => true]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.return-team-leader', $freeUnit))
        ->assertOk()->assertJson(['success' => true]);

    expect($freeUnit->fresh()->team_leader_id)->toBeNull();
    expect($tlHomeUnit->fresh()->team_leader_id)->toBe($tl->id);
    expect($freeUnit->fresh()->driver_name)->toBeNull();
    expect($freeUnit->fresh()->crew_member_1_name)->toBe('Carlo Reyes');
    expect($driverSource->fresh()->driver_name)->toBe('Juan Cruz');
});

it('78. Reserved locks still behave exactly as before (Assign, Borrow, Transfer all rejected)', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $reservedUnit = ulUnit();
    $sourceUnit = ulUnit(['driver_name' => 'Juan Cruz']);
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $reservedUnit->truck_type_id,
        'selected_unit_id' => $reservedUnit->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'confirmed',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.assign-team-leader', $reservedUnit), ['team_leader_id' => $tl->id])
        ->assertStatus(422);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.assign-slot', $reservedUnit), [
            'to_slot' => 'driver_1', 'source_unit_id' => $sourceUnit->id, 'from_slot' => 'driver_1',
        ])->assertStatus(422);

    $freeUnitWithTeam = ulUnit(['team_leader_id' => ulTeamLeader()->id]);
    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.transfer-team', $freeUnitWithTeam), ['target_unit_id' => $reservedUnit->id])
        ->assertStatus(422);

    expect($reservedUnit->fresh()->team_leader_id)->toBeNull();
    expect($reservedUnit->fresh()->driver_name)->toBeNull();
});

it('79. UI does not render the Duty toggle for a Busy Team Leader, showing "Locked while on job" instead', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader('Busy Leader');
    $unit = ulUnit(['name' => 'JARZ Kilo Busy', 'team_leader_id' => $tl->id]);
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $tl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'on_the_way',
    ]);

    $html = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->getContent();

    expect($html)->toContain('JARZ Kilo Busy')
        ->and($html)->toContain('Locked while on job')
        ->and($html)->not->toContain('data-action="toggle-tl-duty"');
});

it('80. UI still renders the Duty toggle for an eligible Free Team Leader', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader('Free Leader');
    ulUnit(['name' => 'JARZ Lima Free', 'team_leader_id' => $tl->id]);

    $html = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->getContent();

    expect($html)->toContain('JARZ Lima Free')
        ->and($html)->toContain('data-action="toggle-tl-duty"')
        ->and($html)->not->toContain('Locked while on job');
});

// ===========================================================================
// SLOT-LEVEL REMOVE — detaches a REGULAR (non-borrowed) assignment. Distinct
// from Return (restores a borrowed person to their home Unit) and from
// Transfer Team (moves the whole roster). Reuses the exact same
// assertUnitTeamIsMovable()/activeLoansIn() guards as every other mutation
// in UnitTeamAssignmentService — no separate locking concept.
// ===========================================================================

it('81. Free regular Team Leader -> Remove succeeds', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unit = ulUnit(['team_leader_id' => $tl->id]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.remove-team-leader', $unit))
        ->assertOk()
        ->assertJson(['success' => true]);

    expect($unit->fresh()->team_leader_id)->toBeNull();
});

it('82. Free regular Driver -> Remove succeeds', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $unit = ulUnit(['driver_name' => 'Juan Cruz']);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.remove-slot', $unit), ['slot' => 'driver_1'])
        ->assertOk()
        ->assertJson(['success' => true]);

    expect($unit->fresh()->driver_name)->toBeNull();
});

it('83. Free regular Crew 1 -> Remove succeeds', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $unit = ulUnit(['crew_member_1_name' => 'Carlo Reyes']);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.remove-slot', $unit), ['slot' => 'crew_member_1'])
        ->assertOk()
        ->assertJson(['success' => true]);

    expect($unit->fresh()->crew_member_1_name)->toBeNull();
});

it('84. Free regular Crew 2 -> Remove succeeds', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $unit = ulUnit(['crew_member_2_name' => 'Nico Santos']);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.remove-slot', $unit), ['slot' => 'crew_member_2'])
        ->assertOk()
        ->assertJson(['success' => true]);

    expect($unit->fresh()->crew_member_2_name)->toBeNull();
});

it('85. Busy Team Leader -> Remove rejected', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unit = ulUnit(['team_leader_id' => $tl->id]);
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $tl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'on_the_way',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.remove-team-leader', $unit))
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($unit->fresh()->team_leader_id)->toBe($tl->id);
});

it('86. Busy Driver -> Remove rejected', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $unit = ulUnit(['driver_name' => 'Juan Cruz']);
    $busyTl = ulTeamLeader();
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $busyTl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'in_progress',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.remove-slot', $unit), ['slot' => 'driver_1'])
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($unit->fresh()->driver_name)->toBe('Juan Cruz');
});

it('87. Busy Crew -> Remove rejected', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $unit = ulUnit(['crew_member_1_name' => 'Carlo Reyes']);
    $busyTl = ulTeamLeader();
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $busyTl->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'loading_vehicle',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.remove-slot', $unit), ['slot' => 'crew_member_1'])
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($unit->fresh()->crew_member_1_name)->toBe('Carlo Reyes');
});

it('88. Reserved Unit Team Leader -> Remove rejected', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unit = ulUnit(['team_leader_id' => $tl->id]);
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'selected_unit_id' => $unit->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'confirmed',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.remove-team-leader', $unit))
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($unit->fresh()->team_leader_id)->toBe($tl->id);
});

it('89. Reserved Unit Driver -> Remove rejected', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $unit = ulUnit(['driver_name' => 'Juan Cruz']);
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'selected_unit_id' => $unit->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'confirmed',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.remove-slot', $unit), ['slot' => 'driver_1'])
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($unit->fresh()->driver_name)->toBe('Juan Cruz');
});

it('90. Reserved Unit Crew -> Remove rejected', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $unit = ulUnit(['crew_member_2_name' => 'Nico Santos']);
    $customer = ulCustomer();

    Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $unit->truck_type_id,
        'selected_unit_id' => $unit->id,
        'age' => 30, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 75, 'computed_total' => 1875, 'final_total' => 1875,
        'status' => 'confirmed',
    ]);

    $this->actingAs($dispatcher)
        ->postJson(route('admin.drivers.units.remove-slot', $unit), ['slot' => 'crew_member_2'])
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($unit->fresh()->crew_member_2_name)->toBe('Nico Santos');
});

it('91. Borrowed Team Leader shows Return (not Remove) in the drawer, and Remove is rejected server-side', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader('Marco Borrowed');
    $unitA = ulUnit(['name' => 'JARZ Home One', 'team_leader_id' => $tl->id]);
    $unitB = ulUnit(['name' => 'JARZ Away One']);
    app(UnitTeamAssignmentService::class)->assignTeamLeader($unitB, $tl->id, $dispatcher);

    $html = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->getContent();
    expect($html)->toContain('Marco Borrowed')
        ->and($html)->toContain('data-action="return-team-leader"')
        ->and($html)->not->toContain('data-action="remove-team-leader"');

    expect(fn () => app(UnitTeamAssignmentService::class)->removeTeamLeader($unitB, $dispatcher))
        ->toThrow(RuntimeException::class);

    expect($unitB->fresh()->team_leader_id)->toBe($tl->id);
});

it('92. Borrowed Driver shows Return (not Remove) in the drawer, and Remove is rejected server-side', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $unitA = ulUnit(['name' => 'JARZ Home Two', 'driver_name' => 'Edwin Flores']);
    $unitB = ulUnit(['name' => 'JARZ Away Two']);
    app(UnitTeamAssignmentService::class)->assignSlotPerson($unitB, 'driver_1', $unitA, 'driver_1', $dispatcher);

    $html = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->getContent();
    expect($html)->toContain('Edwin Flores')
        ->and($html)->toContain('data-action="return-slot"');

    expect(fn () => app(UnitTeamAssignmentService::class)->removeSlotPerson($unitB, 'driver_1', $dispatcher))
        ->toThrow(RuntimeException::class);

    expect($unitB->fresh()->driver_name)->toBe('Edwin Flores');
});

it('93. Borrowed Crew shows Return (not Remove) in the drawer, and Remove is rejected server-side', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $unitA = ulUnit(['name' => 'JARZ Home Three', 'crew_member_1_name' => 'Chris Manalo']);
    $unitB = ulUnit(['name' => 'JARZ Away Three']);
    app(UnitTeamAssignmentService::class)->assignSlotPerson($unitB, 'crew_member_1', $unitA, 'crew_member_1', $dispatcher);

    $html = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->getContent();
    expect($html)->toContain('Chris Manalo')
        ->and($html)->toContain('data-action="return-slot"');

    expect(fn () => app(UnitTeamAssignmentService::class)->removeSlotPerson($unitB, 'crew_member_1', $dispatcher))
        ->toThrow(RuntimeException::class);

    expect($unitB->fresh()->crew_member_1_name)->toBe('Chris Manalo');
});

it('94. Removing the Team Leader does not remove Driver/Crew', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unit = ulUnit([
        'team_leader_id' => $tl->id,
        'driver_name' => 'Juan Cruz',
        'crew_member_1_name' => 'Carlo Reyes',
        'crew_member_2_name' => 'Nico Santos',
    ]);

    app(UnitTeamAssignmentService::class)->removeTeamLeader($unit, $dispatcher);

    $unit->refresh();
    expect($unit->team_leader_id)->toBeNull();
    expect($unit->driver_name)->toBe('Juan Cruz');
    expect($unit->crew_member_1_name)->toBe('Carlo Reyes');
    expect($unit->crew_member_2_name)->toBe('Nico Santos');
});

it('95. Removing the Driver makes the Unit Not Available through the existing availability formula', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unit = ulUnit(['team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz']);

    expect(app(UnitAvailabilityService::class)->evaluate($unit->fresh())['available'])->toBeTrue();

    app(UnitTeamAssignmentService::class)->removeSlotPerson($unit, 'driver_1', $dispatcher);

    $result = app(UnitAvailabilityService::class)->evaluate($unit->fresh());
    expect($result['available'])->toBeFalse();
    expect($result['reasons'])->toContain('no_driver');
});

it('96. Removing optional Crew does not by itself make an otherwise eligible Unit unavailable', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $unit = ulUnit(['team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz', 'crew_member_1_name' => 'Carlo Reyes']);

    app(UnitTeamAssignmentService::class)->removeSlotPerson($unit, 'crew_member_1', $dispatcher);

    $result = app(UnitAvailabilityService::class)->evaluate($unit->fresh());
    expect($result['available'])->toBeTrue();
    expect($unit->fresh()->crew_member_1_name)->toBeNull();
});

it('97. Free-unit Assign/Borrow/Return/Transfer regression still works alongside the new Remove action', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader();
    $driverSource = ulUnit(['driver_name' => 'Juan Cruz']);
    $unitA = ulUnit(['team_leader_id' => $tl->id]);
    $unitB = ulUnit();

    $svc = app(UnitTeamAssignmentService::class);

    // Assign/Borrow still work.
    $svc->assignSlotPerson($unitA, 'driver_1', $driverSource, 'driver_1', $dispatcher);
    expect($unitA->fresh()->driver_name)->toBe('Juan Cruz');

    // Transfer Team still works (moves TL + Driver together).
    $svc->transferTeam($unitA, $unitB, $dispatcher);
    expect($unitB->fresh()->team_leader_id)->toBe($tl->id);
    expect($unitB->fresh()->driver_name)->toBe('Juan Cruz');

    // Return still works for the borrowed driver, now sitting on unitB —
    // transferTeam's own loan chains from unitA (not the original
    // driverSource), so this hop restores to unitA, exactly like any other
    // individual Return of a transferred person.
    $loan = UnitCrewLoan::where('to_unit_id', $unitB->id)->where('to_slot', 'driver_1')->whereNull('returned_at')->firstOrFail();
    $svc->returnSlotPerson($loan, $dispatcher);
    expect($unitA->fresh()->driver_name)->toBe('Juan Cruz');
    expect($unitB->fresh()->driver_name)->toBeNull();

    // Remove still works for a genuinely regular (never-borrowed) assignment.
    $tl2 = ulTeamLeader();
    $unitC = ulUnit(['team_leader_id' => $tl2->id]);
    $svc->removeTeamLeader($unitC, $dispatcher);
    expect($unitC->fresh()->team_leader_id)->toBeNull();
});

it('98. Drawer renders Assign for an empty slot, Remove for a regular slot, and Return for a borrowed slot in one view', function () {
    ulRoles();
    $dispatcher = ulDispatcher();
    $tl = ulTeamLeader('Liza Torres');
    $unitHome = ulUnit(['name' => 'JARZ Zero', 'crew_member_1_name' => 'Chris Manalo']);
    $unit = ulUnit(['name' => 'JARZ Mixed', 'team_leader_id' => $tl->id, 'driver_name' => 'Edwin Flores']);
    app(UnitTeamAssignmentService::class)->assignSlotPerson($unit, 'crew_member_1', $unitHome, 'crew_member_1', $dispatcher);
    // crew_member_2 left empty -> Assign; team_leader/driver are regular -> Remove; crew_1 is borrowed -> Return.

    $html = $this->actingAs($dispatcher)->get(route('admin.drivers'))->assertOk()->getContent();

    expect($html)->toContain('JARZ Mixed')
        ->and($html)->toContain('Liza Torres')
        ->and($html)->toContain('Edwin Flores')
        ->and($html)->toContain('Chris Manalo')
        ->and($html)->toContain('data-action="remove-team-leader"')
        ->and($html)->toContain('data-action="remove-slot" data-unit-id="' . $unit->id . '" data-slot="driver_1"')
        ->and($html)->toContain('data-action="return-slot"')
        ->and($html)->toContain('data-action="open-assign" data-role="crew" data-unit-id="' . $unit->id . '" data-slot="crew_member_2"')
        ->and($html)->toContain('Not assigned');
});
