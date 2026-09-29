<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

function tldiRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function (Role $role) use ($id) {
        $role->id = $id;
        $role->save();
    });
}

function tldiTeamLeader(): User
{
    tldiRole(3, 'Team Leader');

    return User::factory()->create([
        'role_id' => 3,
        'must_change_password' => false,
    ]);
}

function tldiDispatcher(): User
{
    tldiRole(2, 'Dispatcher');

    return User::factory()->create([
        'role_id' => 2,
        'must_change_password' => false,
    ]);
}

function tldiTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'Deferred Invoice Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 300,
    ]);
}

function tldiUnit(TruckType $truckType, User $teamLeader, string $status = 'on_job'): Unit
{
    return Unit::create([
        'name' => 'Deferred Invoice Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => $status,
    ]);
}

function tldiBooking(TruckType $truckType, Unit $unit, User $teamLeader, array $overrides = []): Booking
{
    $customer = Customer::create([
        'full_name' => 'Deferred Invoice Customer',
        'phone' => '09171234567',
        'email' => 'tl-deferred-invoice-' . uniqid() . '@example.com',
    ]);

    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $teamLeader->id,
        'pickup_address' => 'Quezon City Test Pickup',
        'pickup_lat' => 14.6760,
        'pickup_lng' => 121.0437,
        'dropoff_address' => 'Makati Test Dropoff',
        'dropoff_lat' => 14.5547,
        'dropoff_lng' => 121.0244,
        'distance_km' => 12.5,
        'base_rate' => 1500,
        'per_km_rate' => 300,
        'computed_total' => 4050,
        'final_total' => 4536,
        'vat_exclusive_total' => 4050,
        'status' => 'on_job',
        'assigned_at' => now(),
    ], $overrides));
}

function tldiCompleteUrl(Booking $booking): string
{
    return '/api/v1/team-leader/task/' . $booking->booking_code . '/complete';
}

it('1: reaching arrived_dropoff via updateStatus() creates no invoice', function () {
    $teamLeader = tldiTeamLeader();
    $truckType = tldiTruckType();
    $unit = tldiUnit($truckType, $teamLeader);
    $booking = tldiBooking($truckType, $unit, $teamLeader, ['status' => 'on_job']);

    Sanctum::actingAs($teamLeader, ['*']);

    $this->patchJson("/api/v1/team-leader/task/{$booking->booking_code}/status", [
        'status' => 'arrived_dropoff',
        'lat' => $booking->dropoff_lat,
        'lng' => $booking->dropoff_lng,
    ])->assertOk();

    expect($booking->fresh()->status)->toBe('arrived_dropoff');
    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(0);
});

it('2a: Return from arrived_dropoff leaves no invoice', function () {
    $teamLeader = tldiTeamLeader();
    $truckType = tldiTruckType();
    $unit = tldiUnit($truckType, $teamLeader);
    $booking = tldiBooking($truckType, $unit, $teamLeader, ['status' => 'arrived_dropoff']);

    Sanctum::actingAs($teamLeader, ['*']);

    $this->postJson("/api/v1/team-leader/task/{$booking->booking_code}/return", [
        'reason' => 'Vehicle/Unit Issue',
    ])->assertOk();

    expect($booking->fresh()->status)->toBe('returned');
    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(0);
});

it('2b: Reassign after Return reaches arrived_dropoff again with still no invoice', function () {
    $teamLeader = tldiTeamLeader();
    $truckType = tldiTruckType();
    $unit = tldiUnit($truckType, $teamLeader);
    $booking = tldiBooking($truckType, $unit, $teamLeader, ['status' => 'arrived_dropoff']);

    Sanctum::actingAs($teamLeader, ['*']);
    $this->postJson("/api/v1/team-leader/task/{$booking->booking_code}/return", [
        'reason' => 'Cannot Reach Pickup Location',
    ])->assertOk();

    $newLeader = tldiTeamLeader();
    $newUnit = tldiUnit($truckType, $newLeader, 'available');

    $this->actingAs(tldiDispatcher())
        ->postJson(route('admin.booking.assign', $booking), [
            'action' => 'accept',
            'assigned_unit_id' => $newUnit->id,
        ])
        ->assertOk()
        ->assertJsonPath('status', 'assigned');

    expect($booking->fresh()->status)->toBe('assigned');
    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(0);

    Sanctum::actingAs($newLeader, ['*']);
    $this->postJson("/api/v1/team-leader/task/{$booking->booking_code}/accept")->assertOk();

    // Walk the real forward transitions for the new cycle, exactly as the
    // updateStatus() route enforces them, back up to arrived_dropoff again.
    $arrivalCoords = [
        'arrived_pickup'  => ['lat' => $booking->pickup_lat, 'lng' => $booking->pickup_lng],
        'arrived_dropoff' => ['lat' => $booking->dropoff_lat, 'lng' => $booking->dropoff_lng],
    ];
    foreach (['on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff'] as $status) {
        $this->patchJson("/api/v1/team-leader/task/{$booking->booking_code}/status", array_merge(
            ['status' => $status],
            $arrivalCoords[$status] ?? [],
        ))->assertOk();
    }

    expect($booking->fresh()->status)->toBe('arrived_dropoff');
    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(0);
});

it('2c: Cancel a returned task leaves no invoice', function () {
    $teamLeader = tldiTeamLeader();
    $truckType = tldiTruckType();
    $unit = tldiUnit($truckType, $teamLeader);
    $booking = tldiBooking($truckType, $unit, $teamLeader, ['status' => 'arrived_dropoff']);

    Sanctum::actingAs($teamLeader, ['*']);
    $this->postJson("/api/v1/team-leader/task/{$booking->booking_code}/return", [
        'reason' => 'Other',
    ])->assertOk();

    $this->actingAs(tldiDispatcher())
        ->postJson(route('admin.booking.assign', $booking), [
            'action' => 'reject',
            'rejection_reason' => 'Customer no longer needs the service.',
        ])
        ->assertOk();

    expect($booking->fresh()->status)->toBe('cancelled');
    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(0);
});

it('3: successful single complete() creates exactly one invoice and reaches waiting_verification', function () {
    $teamLeader = tldiTeamLeader();
    $truckType = tldiTruckType();
    $unit = tldiUnit($truckType, $teamLeader);
    $booking = tldiBooking($truckType, $unit, $teamLeader, [
        'status' => 'arrived_dropoff',
        'payment_proof_path' => 'task-photos/fake-proof.jpg',
    ]);

    $quotation = Quotation::create([
        'quotation_number' => 'Q-TLDI-' . uniqid(),
        'source_booking_id' => $booking->id,
        'customer_id' => $booking->customer_id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => $booking->pickup_address,
        'dropoff_address' => $booking->dropoff_address,
        'distance_km' => 12.5,
        'estimated_price' => 4536,
        'status' => 'accepted',
    ]);
    $booking->update(['quotation_id' => $quotation->id]);

    Sanctum::actingAs($teamLeader, ['*']);

    $this->post(tldiCompleteUrl($booking), [
        'payment_method' => 'cash',
        'cash_received' => 4536,
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertOk()->assertJsonPath('success', true);

    $booking->refresh();
    expect($booking->status)->toBe('waiting_verification');
    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(1);

    $invoice = Invoice::where('booking_id', $booking->id)->first();
    expect($invoice->status)->toBe('issued');
    expect($invoice->is_current)->toBeTrue();
    expect((float) $invoice->total)->toBe(4536.0);
});

it('4: a retried complete() request on an already-submitted task does not create a duplicate invoice', function () {
    $teamLeader = tldiTeamLeader();
    $truckType = tldiTruckType();
    $unit = tldiUnit($truckType, $teamLeader);
    $booking = tldiBooking($truckType, $unit, $teamLeader, [
        'status' => 'arrived_dropoff',
        'payment_proof_path' => 'task-photos/fake-proof.jpg',
    ]);

    Sanctum::actingAs($teamLeader, ['*']);

    $this->post(tldiCompleteUrl($booking), [
        'payment_method' => 'cash',
        'cash_received' => 4536,
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertOk();

    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(1);

    // Same team leader's client retries the exact same request (e.g. a flaky
    // network response) after the task already moved to waiting_verification.
    $this->post(tldiCompleteUrl($booking), [
        'payment_method' => 'cash',
        'cash_received' => 4536,
        'signature' => UploadedFile::fake()->image('sig2.jpg'),
    ])->assertOk()->assertJsonPath('message', 'Task already submitted for dispatcher confirmation.');

    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(1);
});

it('5: grouped completion still issues its own invoice at complete(), unaffected by the single-booking deferral', function () {
    $teamLeader = tldiTeamLeader();
    $truckType = tldiTruckType();
    $unit = tldiUnit($truckType, $teamLeader);

    $anchor = tldiBooking($truckType, $unit, $teamLeader, [
        'status' => 'arrived_dropoff',
        'group_code' => 'GRP-TLDI-' . uniqid(),
        'payment_proof_path' => 'task-photos/fake-proof.jpg',
    ]);
    $sibling = tldiBooking($truckType, $unit, $teamLeader, [
        'status' => 'arrived_dropoff',
        'group_code' => $anchor->group_code,
        'pickup_address' => $anchor->pickup_address,
        'dropoff_address' => $anchor->dropoff_address,
    ]);

    $quotation = Quotation::create([
        'quotation_number' => 'Q-TLDI-GRP-' . uniqid(),
        'source_booking_id' => $anchor->id,
        'customer_id' => $anchor->customer_id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => $anchor->pickup_address,
        'dropoff_address' => $anchor->dropoff_address,
        'distance_km' => 12.5,
        'estimated_price' => 9072,
        'status' => 'accepted',
        'extra_vehicles' => [
            ['booking_id' => $anchor->id, 'final_total' => 4536, 'estimated_price' => 4536, 'truck_type_name' => $truckType->name],
            ['booking_id' => $sibling->id, 'final_total' => 4536, 'estimated_price' => 4536, 'truck_type_name' => $truckType->name],
        ],
    ]);
    $anchor->update(['quotation_id' => $quotation->id]);
    $sibling->update(['quotation_id' => $quotation->id]);

    // Grouped bookings never get an invoice at arrived_dropoff — unaffected
    // by this change (they already deferred to complete()/completeGroup()).
    expect(Invoice::where('booking_id', $anchor->id)->count())->toBe(0);
    expect(Invoice::where('booking_id', $sibling->id)->count())->toBe(0);

    Sanctum::actingAs($teamLeader, ['*']);

    $this->post(tldiCompleteUrl($anchor), [
        'payment_method' => 'cash',
        'cash_received' => 9072,
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertOk()->assertJsonPath('success', true);

    expect($anchor->fresh()->status)->toBe('waiting_verification');
    expect($sibling->fresh()->status)->toBe('waiting_verification');
    expect(Invoice::where('booking_id', $anchor->id)->count())->toBe(1);
});
