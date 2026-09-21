<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

function gauDispatcher(): User
{
    $role = Role::find(2) ?: tap(new Role(['name' => 'Dispatcher']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id]);
}

function gauCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'GAU Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'gau-' . uniqid() . '@example.com',
    ]);
}

function gauTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'GAU Medium Duty ' . fake()->unique()->word(),
        'base_rate' => 2500,
        'per_km_rate' => 300,
        'status' => 'active',
    ]);
}

function gauReadyUnit(TruckType $truckType, string $label): array
{
    $role = Role::firstOrCreate(['name' => 'Team Leader'], ['description' => 'Tow unit team leader']);
    $leader = User::factory()->create(['role_id' => $role->id]);
    Cache::put("teamleader:presence:{$leader->id}", now()->timestamp, now()->addMinutes(2));

    $unit = Unit::create([
        'name' => $label,
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $leader->id,
        'status' => 'available',
    ]);

    return [$unit, $leader];
}

function gauGroupedBooking(Customer $customer, TruckType $truckType, string $groupCode): Booking
{
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'group_code' => $groupCode,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'final_total' => 4658.08,
        'vat_amount' => 499.08,
        'vat_exclusive_total' => 4159.00,
        'status' => 'confirmed',
        'service_type' => 'book_now',
        'customer_approved_at' => now(),
        'price_locked_at' => now(),
    ]);

    return $booking->fresh(['customer', 'truckType']);
}

function gauAcceptedGroupQuotation(Customer $customer, TruckType $truckType, Booking $bookingA, Booking $bookingB): Quotation
{
    $quotation = Quotation::create([
        'quotation_number' => 'QT-GAU-' . uniqid(),
        'source_booking_id' => $bookingA->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'estimated_price' => 9316.16,
        'service_type' => 'book_now',
        'status' => 'accepted',
        'extra_vehicles' => [
            ['booking_id' => $bookingA->id, 'truck_type_id' => $truckType->id, 'final_total' => 4658.08],
            ['booking_id' => $bookingB->id, 'truck_type_id' => $truckType->id, 'final_total' => 4658.08],
        ],
    ]);

    $bookingA->update(['quotation_id' => $quotation->id]);
    $bookingB->update(['quotation_id' => $quotation->id]);

    return $quotation;
}

it('assigns the first sibling, then the second sibling independently, then updates the first assignment safely', function () {
    $dispatcher = gauDispatcher();
    $customer = gauCustomer();
    $truckType = gauTruckType();
    $groupCode = 'GAU-GROUP-001';

    $bookingA = gauGroupedBooking($customer, $truckType, $groupCode);
    $bookingB = gauGroupedBooking($customer, $truckType, $groupCode);
    gauAcceptedGroupQuotation($customer, $truckType, $bookingA, $bookingB);

    [$unitA, $leaderA] = gauReadyUnit($truckType, 'GAU Unit A');
    [$unitB, $leaderB] = gauReadyUnit($truckType, 'GAU Unit B');

    $responseA = test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingA), [
        'action' => 'accept',
        'assigned_unit_id' => $unitA->id,
    ]);
    $responseA->assertOk()->assertJson(['success' => true]);

    $bookingA->refresh();
    expect($bookingA->status)->toBe('assigned')
        ->and($bookingA->assigned_unit_id)->toBe($unitA->id)
        ->and($bookingA->assigned_team_leader_id)->toBe($leaderA->id);

    $responseB = test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingB), [
        'action' => 'accept',
        'assigned_unit_id' => $unitB->id,
    ]);
    $responseB->assertOk()->assertJson(['success' => true]);

    $bookingB->refresh();
    expect($bookingB->status)->toBe('assigned')
        ->and($bookingB->assigned_unit_id)->toBe($unitB->id)
        ->and($bookingB->assigned_team_leader_id)->toBe($leaderB->id);

    $updateA = test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingA), [
        'action' => 'accept',
        'assigned_unit_id' => $unitA->id,
    ]);
    $updateA->assertOk()->assertJson(['success' => true]);

    $bookingA->refresh();
    expect($bookingA->status)->toBe('assigned')
        ->and($bookingA->assigned_unit_id)->toBe($unitA->id)
        ->and((float) $bookingA->final_total)->toBe(4658.08)
        ->and((float) $bookingB->fresh()->final_total)->toBe(4658.08);

    $stealAttempt = test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingB), [
        'action' => 'accept',
        'assigned_unit_id' => $unitA->id,
    ]);
    $stealAttempt->assertStatus(422);

    expect(Booking::where('group_code', $groupCode)->count())->toBe(2);
});

it('keeps single-vehicle Book Now assignment behavior unchanged', function () {
    $dispatcher = gauDispatcher();
    $customer = gauCustomer();
    $truckType = gauTruckType();

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'final_total' => 4658.08,
        'status' => 'confirmed',
        'service_type' => 'book_now',
        'customer_approved_at' => now(),
        'price_locked_at' => now(),
    ]);

    $quotation = Quotation::create([
        'quotation_number' => 'QT-GAU-SOLO-' . uniqid(),
        'source_booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'estimated_price' => 4658.08,
        'service_type' => 'book_now',
        'status' => 'accepted',
    ]);
    $booking->update(['quotation_id' => $quotation->id]);

    [$unit] = gauReadyUnit($truckType, 'GAU Solo Unit');

    $response = test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $booking), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
    ]);

    $response->assertOk()->assertJson(['success' => true]);
    expect($booking->fresh()->status)->toBe('assigned')
        ->and($booking->fresh()->assigned_unit_id)->toBe($unit->id);
});

it('represents a normalized grouped Book Now request as one Active Jobs row after both siblings are assigned', function () {
    $dispatcher = gauDispatcher();
    $customer = gauCustomer();
    $truckType = gauTruckType();
    $groupCode = 'GAU-GROUP-ACTIVE-JOBS';

    $bookingA = gauGroupedBooking($customer, $truckType, $groupCode);
    $bookingB = gauGroupedBooking($customer, $truckType, $groupCode);
    gauAcceptedGroupQuotation($customer, $truckType, $bookingA, $bookingB);

    [$unitA, $leaderA] = gauReadyUnit($truckType, 'GAU Active Unit A');
    [$unitB, $leaderB] = gauReadyUnit($truckType, 'GAU Active Unit B');

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingA), [
        'action' => 'accept',
        'assigned_unit_id' => $unitA->id,
    ])->assertOk()->assertJson(['success' => true]);

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingB), [
        'action' => 'accept',
        'assigned_unit_id' => $unitB->id,
    ])->assertOk()->assertJson(['success' => true]);

    $html = test()->actingAs($dispatcher)->get(route('admin.jobs'))->assertOk()->getContent();

    $primaryCode = $bookingA->booking_code;
    $siblingCode = $bookingB->booking_code;

    $primaryOccurrences = substr_count($html, 'data-booking-code="' . $primaryCode . '"');
    $siblingOccurrences = substr_count($html, 'data-booking-code="' . $siblingCode . '"');

    expect($primaryOccurrences)->toBe(1)
        ->and($siblingOccurrences)->toBe(0)
        ->and($html)->toContain('(+1)');

    $pos = strpos($html, 'data-booking-code="' . $primaryCode . '"');
    $rowStart = strrpos(substr($html, 0, $pos), '<tr class="jobs-row');
    $rowEnd = strpos($html, '</tr>', $pos);
    $row = substr($html, $rowStart, $rowEnd - $rowStart);

    preg_match('/data-group-vehicles="([^"]*)"/', $row, $matches);
    expect($matches)->toHaveCount(2);

    $groupVehicles = json_decode(html_entity_decode($matches[1]), true);
    expect($groupVehicles)->toHaveCount(2);

    $codes = array_column($groupVehicles, 'booking_code');
    expect($codes)->toContain($primaryCode)
        ->and($codes)->toContain($siblingCode);

    $entryA = collect($groupVehicles)->firstWhere('booking_code', $primaryCode);
    $entryB = collect($groupVehicles)->firstWhere('booking_code', $siblingCode);

    expect($entryA['unit'])->toBe($unitA->name)
        ->and($entryA['team_leader'])->toBe($leaderA->full_name ?? $leaderA->name)
        ->and($entryB['unit'])->toBe($unitB->name)
        ->and($entryB['team_leader'])->toBe($leaderB->full_name ?? $leaderB->name);

    expect(Booking::where('group_code', $groupCode)->count())->toBe(2);
    expect($bookingA->fresh()->id)->not->toBe($bookingB->fresh()->id);
});

it('keeps a solo grouped sibling (only one vehicle assigned so far) as its own ungrouped Active Jobs row', function () {
    $dispatcher = gauDispatcher();
    $customer = gauCustomer();
    $truckType = gauTruckType();
    $groupCode = 'GAU-GROUP-PARTIAL';

    $bookingA = gauGroupedBooking($customer, $truckType, $groupCode);
    $bookingB = gauGroupedBooking($customer, $truckType, $groupCode);
    gauAcceptedGroupQuotation($customer, $truckType, $bookingA, $bookingB);

    [$unitA] = gauReadyUnit($truckType, 'GAU Partial Unit A');

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingA), [
        'action' => 'accept',
        'assigned_unit_id' => $unitA->id,
    ])->assertOk()->assertJson(['success' => true]);

    $html = test()->actingAs($dispatcher)->get(route('admin.jobs'))->assertOk()->getContent();

    expect($html)->toContain('data-booking-code="' . $bookingA->booking_code . '"')
        ->and($html)->not->toContain('data-booking-code="' . $bookingB->booking_code . '"')
        ->and($html)->not->toContain('(+1)');

    expect($bookingB->fresh()->status)->toBe('confirmed');
});

it('keeps both grouped Team Leader tasks independently active when the second sibling accepts after the first', function () {
    $dispatcher = gauDispatcher();
    $customer = gauCustomer();
    $truckType = gauTruckType();
    $groupCode = 'GAU-GROUP-TL-ACCEPT';

    $bookingA = gauGroupedBooking($customer, $truckType, $groupCode);
    $bookingB = gauGroupedBooking($customer, $truckType, $groupCode);
    gauAcceptedGroupQuotation($customer, $truckType, $bookingA, $bookingB);

    [$unitA, $leaderA] = gauReadyUnit($truckType, 'GAU TL Accept Unit A');
    [$unitB, $leaderB] = gauReadyUnit($truckType, 'GAU TL Accept Unit B');
    $leaderA->forceFill(['must_change_password' => false])->save();
    $leaderB->forceFill(['must_change_password' => false])->save();

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingA), [
        'action' => 'accept',
        'assigned_unit_id' => $unitA->id,
    ])->assertOk()->assertJson(['success' => true]);

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingB), [
        'action' => 'accept',
        'assigned_unit_id' => $unitB->id,
    ])->assertOk()->assertJson(['success' => true]);

    Sanctum::actingAs($leaderA, ['*']);
    $acceptA = test()->postJson('/api/v1/team-leader/task/' . $bookingA->booking_code . '/accept');
    $acceptA->assertOk()->assertJson(['success' => true]);

    $currentA = test()->getJson('/api/v1/team-leader/task');
    $currentA->assertOk()
        ->assertJsonPath('data.booking_code', $bookingA->booking_code)
        ->assertJsonPath('data.final_total', 4658.08)
        ->assertJsonPath('data.group_vehicle_count', 2)
        ->assertJsonPath('data.group_position', 1);

    Sanctum::actingAs($leaderB, ['*']);
    $acceptB = test()->postJson('/api/v1/team-leader/task/' . $bookingB->booking_code . '/accept');
    $acceptB->assertOk()->assertJson(['success' => true]);

    $bookingA->refresh();
    expect($bookingA->status)->toBe('accepted')
        ->and($bookingA->assigned_team_leader_id)->toBe($leaderA->id)
        ->and($bookingA->assigned_unit_id)->toBe($unitA->id)
        ->and((float) $bookingA->final_total)->toBe(4658.08);

    Sanctum::actingAs($leaderA, ['*']);
    $recheckA = test()->getJson('/api/v1/team-leader/task');
    $recheckA->assertOk()
        ->assertJsonPath('data.booking_code', $bookingA->booking_code)
        ->assertJsonPath('data.final_total', 4658.08);

    Sanctum::actingAs($leaderB, ['*']);
    $currentB = test()->getJson('/api/v1/team-leader/task');
    $currentB->assertOk()
        ->assertJsonPath('data.booking_code', $bookingB->booking_code)
        ->assertJsonPath('data.final_total', 4658.08)
        ->assertJsonPath('data.group_vehicle_count', 2)
        ->assertJsonPath('data.group_position', 2);

    $bookingB->refresh();
    expect($bookingB->status)->toBe('accepted')
        ->and($bookingB->assigned_team_leader_id)->toBe($leaderB->id)
        ->and($bookingB->assigned_unit_id)->toBe($unitB->id)
        ->and((float) $bookingB->final_total)->toBe(4658.08);

    expect(Booking::where('group_code', $groupCode)->count())->toBe(2);
});

it('confirms a real pickup arrival succeeds and rejects a duplicate retry without touching the sibling', function () {
    $dispatcher = gauDispatcher();
    $customer = gauCustomer();
    $truckType = gauTruckType();
    $groupCode = 'GAU-GROUP-ARRIVAL';

    $bookingA = gauGroupedBooking($customer, $truckType, $groupCode);
    $bookingB = gauGroupedBooking($customer, $truckType, $groupCode);
    $bookingA->update(['pickup_lat' => 14.7054035, 'pickup_lng' => 121.0463671]);
    $bookingB->update(['pickup_lat' => 14.7054035, 'pickup_lng' => 121.0463671]);
    gauAcceptedGroupQuotation($customer, $truckType, $bookingA, $bookingB);

    [$unitA, $leaderA] = gauReadyUnit($truckType, 'GAU Arrival Unit A');
    [$unitB, $leaderB] = gauReadyUnit($truckType, 'GAU Arrival Unit B');
    $leaderA->forceFill(['must_change_password' => false])->save();
    $leaderB->forceFill(['must_change_password' => false])->save();

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingA), [
        'action' => 'accept',
        'assigned_unit_id' => $unitA->id,
    ])->assertOk();

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingB), [
        'action' => 'accept',
        'assigned_unit_id' => $unitB->id,
    ])->assertOk();

    Sanctum::actingAs($leaderA, ['*']);
    test()->postJson('/api/v1/team-leader/task/' . $bookingA->booking_code . '/accept')->assertOk();
    test()->patchJson('/api/v1/team-leader/task/' . $bookingA->booking_code . '/status', [
        'status' => 'on_the_way',
    ])->assertOk();

    $bookingBUpdatedAtBefore = $bookingB->fresh()->updated_at;

    $arrive = test()->patchJson('/api/v1/team-leader/task/' . $bookingA->booking_code . '/status', [
        'status' => 'arrived_pickup',
        'lat' => 14.7054035,
        'lng' => 121.0463671,
    ]);
    $arrive->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'arrived_pickup')
        ->assertJsonPath('data.final_total', 4658.08);

    $bookingA->refresh();
    expect($bookingA->status)->toBe('arrived_pickup');

    $duplicateArrive = test()->patchJson('/api/v1/team-leader/task/' . $bookingA->booking_code . '/status', [
        'status' => 'arrived_pickup',
        'lat' => 14.7054035,
        'lng' => 121.0463671,
    ]);
    $duplicateArrive->assertStatus(422)
        ->assertJsonPath('message', "Cannot transition from 'arrived_pickup' to 'arrived_pickup'.");

    $duplicateDemo = test()->patchJson('/api/v1/team-leader/task/' . $bookingA->booking_code . '/status', [
        'status' => 'arrived_pickup',
        'is_demo' => true,
    ]);
    $duplicateDemo->assertStatus(422)
        ->assertJsonPath('message', "Cannot transition from 'arrived_pickup' to 'arrived_pickup'.");

    $bookingA->refresh();
    expect($bookingA->status)->toBe('arrived_pickup')
        ->and((float) $bookingA->final_total)->toBe(4658.08);

    $bookingB->refresh();
    expect($bookingB->status)->toBe('assigned')
        ->and($bookingB->assigned_team_leader_id)->toBe($leaderB->id)
        ->and($bookingB->updated_at->equalTo($bookingBUpdatedAtBefore))->toBeTrue()
        ->and((float) $bookingB->final_total)->toBe(4658.08);

    Sanctum::actingAs($leaderA, ['*']);
    $currentA = test()->getJson('/api/v1/team-leader/task');
    $currentA->assertOk()->assertJsonPath('data.status', 'arrived_pickup');

    Sanctum::actingAs($leaderB, ['*']);
    $currentB = test()->getJson('/api/v1/team-leader/task');
    $currentB->assertOk()->assertJsonPath('data.status', 'assigned');
});

it('lets Vehicle 2 Demo Arrival advance independently while Vehicle 1 is far along its own lifecycle', function () {
    config(['towmate.demo_arrival_enabled' => true]);
    $dispatcher = gauDispatcher();
    $customer = gauCustomer();
    $truckType = gauTruckType();
    $groupCode = 'GAU-GROUP-DEMO-ARRIVAL';

    $bookingA = gauGroupedBooking($customer, $truckType, $groupCode);
    $bookingB = gauGroupedBooking($customer, $truckType, $groupCode);
    $bookingA->update(['pickup_lat' => 14.7054035, 'pickup_lng' => 121.0463671]);
    $bookingB->update(['pickup_lat' => 14.7054035, 'pickup_lng' => 121.0463671]);
    gauAcceptedGroupQuotation($customer, $truckType, $bookingA, $bookingB);

    [$unitA, $leaderA] = gauReadyUnit($truckType, 'GAU Demo Arrival Unit A');
    [$unitB, $leaderB] = gauReadyUnit($truckType, 'GAU Demo Arrival Unit B');
    $leaderA->forceFill(['must_change_password' => false])->save();
    $leaderB->forceFill(['must_change_password' => false])->save();

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingA), [
        'action' => 'accept',
        'assigned_unit_id' => $unitA->id,
    ])->assertOk();

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingB), [
        'action' => 'accept',
        'assigned_unit_id' => $unitB->id,
    ])->assertOk();

    Sanctum::actingAs($leaderA, ['*']);
    test()->postJson('/api/v1/team-leader/task/' . $bookingA->booking_code . '/accept')->assertOk();
    foreach (['on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'on_job'] as $status) {
        $payload = ['status' => $status];
        if ($status === 'arrived_pickup') {
            $payload['lat'] = 14.7054035;
            $payload['lng'] = 121.0463671;
        }
        test()->patchJson('/api/v1/team-leader/task/' . $bookingA->booking_code . '/status', $payload)
            ->assertOk();
    }

    $bookingA->refresh();
    expect($bookingA->status)->toBe('on_job');
    $bookingAUpdatedAtBefore = $bookingA->updated_at;

    Sanctum::actingAs($leaderB, ['*']);
    test()->postJson('/api/v1/team-leader/task/' . $bookingB->booking_code . '/accept')->assertOk();
    test()->patchJson('/api/v1/team-leader/task/' . $bookingB->booking_code . '/status', [
        'status' => 'on_the_way',
    ])->assertOk();

    $demoArrive = test()->patchJson('/api/v1/team-leader/task/' . $bookingB->booking_code . '/status', [
        'status' => 'arrived_pickup',
        'is_demo' => true,
    ]);
    $demoArrive->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'arrived_pickup')
        ->assertJsonPath('data.final_total', 4658.08);

    $bookingB->refresh();
    expect($bookingB->status)->toBe('arrived_pickup')
        ->and($bookingB->assigned_team_leader_id)->toBe($leaderB->id)
        ->and((float) $bookingB->final_total)->toBe(4658.08);

    $bookingA->refresh();
    expect($bookingA->status)->toBe('on_job')
        ->and($bookingA->assigned_team_leader_id)->toBe($leaderA->id)
        ->and($bookingA->updated_at->equalTo($bookingAUpdatedAtBefore))->toBeTrue()
        ->and((float) $bookingA->final_total)->toBe(4658.08);

    Sanctum::actingAs($leaderA, ['*']);
    $currentA = test()->getJson('/api/v1/team-leader/task');
    $currentA->assertOk()->assertJsonPath('data.status', 'on_job');

    Sanctum::actingAs($leaderB, ['*']);
    $currentB = test()->getJson('/api/v1/team-leader/task');
    $currentB->assertOk()->assertJsonPath('data.status', 'arrived_pickup');
});
