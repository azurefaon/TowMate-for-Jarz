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

function makeCompleteTaskScenario(string $status = 'arrived_dropoff'): array
{
    $teamLeaderRole = Role::find(3);
    if (! $teamLeaderRole) {
        $teamLeaderRole = new Role([
            'name' => 'Team Leader',
            'description' => 'Tow unit team leader',
        ]);
        $teamLeaderRole->id = 3;
        $teamLeaderRole->save();
    }

    $teamLeader = User::factory()->create([
        'role_id' => $teamLeaderRole->id,
        'must_change_password' => false,
    ]);

    $truckType = TruckType::create([
        'name' => 'Complete Task Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 300,
    ]);

    $unit = Unit::create([
        'name' => 'Complete Task Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
    ]);

    $customer = Customer::create([
        'full_name' => 'Complete Task Customer',
        'phone' => '09171234567',
        'email' => 'complete-task-' . uniqid() . '@example.com',
    ]);

    $booking = Booking::create([
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
        'status' => $status,
        'assigned_at' => now(),
    ]);

    $quotation = Quotation::create([
        'quotation_number' => 'Q-COMPLETE-' . uniqid(),
        'source_booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => $booking->pickup_address,
        'dropoff_address' => $booking->dropoff_address,
        'distance_km' => 12.5,
        'estimated_price' => 4536,
        'status' => 'accepted',
    ]);
    $booking->update(['quotation_id' => $quotation->id]);

    Invoice::create([
        'booking_id' => $booking->id,
        'quotation_id' => $quotation->id,
        'subtotal' => 4050,
        'additional_fee' => 0,
        'discount' => 0,
        'total' => 4536,
        'status' => 'issued',
        'is_current' => true,
        'created_by' => $teamLeader->id,
    ]);

    return [$teamLeader, $booking->fresh(), $quotation->fresh()];
}

function completeUrl(Booking $booking): string
{
    return '/api/v1/team-leader/task/' . $booking->booking_code . '/status';
}

function completeTaskUrl(Booking $booking): string
{
    return '/api/v1/team-leader/task/' . $booking->booking_code . '/complete';
}

it('A: arrived_dropoff cannot PATCH directly to waiting_verification', function () {
    [$teamLeader, $booking] = makeCompleteTaskScenario('arrived_dropoff');
    Sanctum::actingAs($teamLeader, ['*']);

    $this->patchJson(completeUrl($booking), [
        'status' => 'waiting_verification',
    ])->assertStatus(422)->assertJsonPath('success', false);

    expect($booking->fresh()->status)->toBe('arrived_dropoff');
});

it('B: arrived_dropoff can complete via complete() with valid cash + proof + signature', function () {
    [$teamLeader, $booking] = makeCompleteTaskScenario('arrived_dropoff');
    $booking->update(['payment_proof_path' => 'task-photos/fake-proof.jpg']);
    Sanctum::actingAs($teamLeader, ['*']);

    $this->post(completeTaskUrl($booking), [
        'payment_method' => 'cash',
        'cash_received' => 5000,
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertOk()->assertJsonPath('success', true);

    expect($booking->fresh()->status)->toBe('waiting_verification');
});

it('C: cash without signature fails', function () {
    [$teamLeader, $booking] = makeCompleteTaskScenario('arrived_dropoff');
    Sanctum::actingAs($teamLeader, ['*']);

    $this->post(completeTaskUrl($booking), [
        'payment_method' => 'cash',
        'cash_received' => 5000,
    ])->assertStatus(422);

    expect($booking->fresh()->status)->toBe('arrived_dropoff');
});

it('D: cash with insufficient amount fails', function () {
    [$teamLeader, $booking] = makeCompleteTaskScenario('arrived_dropoff');
    Sanctum::actingAs($teamLeader, ['*']);

    $this->post(completeTaskUrl($booking), [
        'payment_method' => 'cash',
        'cash_received' => 100,
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertStatus(422)->assertJsonPath('message', 'Cash received must cover the final total.');

    expect($booking->fresh()->status)->toBe('arrived_dropoff');
});

it('E: cash without proof fails (proof is required for cash)', function () {
    [$teamLeader, $booking] = makeCompleteTaskScenario('arrived_dropoff');
    Sanctum::actingAs($teamLeader, ['*']);

    $this->post(completeTaskUrl($booking), [
        'payment_method' => 'cash',
        'cash_received' => 4536,
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Payment proof must be uploaded before completing this task.');

    expect($booking->fresh()->status)->toBe('arrived_dropoff');
});

it('F: gcash without signature fails', function () {
    [$teamLeader, $booking] = makeCompleteTaskScenario('arrived_dropoff');
    $booking->update(['payment_proof_path' => 'task-photos/fake-proof.jpg']);
    Sanctum::actingAs($teamLeader, ['*']);

    $this->post(completeTaskUrl($booking), [
        'payment_method' => 'gcash',
    ])->assertStatus(422);

    expect($booking->fresh()->status)->toBe('arrived_dropoff');
});

it('G: gcash without persisted proof fails', function () {
    [$teamLeader, $booking] = makeCompleteTaskScenario('arrived_dropoff');
    Sanctum::actingAs($teamLeader, ['*']);

    $this->post(completeTaskUrl($booking), [
        'payment_method' => 'gcash',
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Payment proof must be uploaded before completing this task.');

    expect($booking->fresh()->status)->toBe('arrived_dropoff');
});

it('H: gcash with signature + persisted proof succeeds', function () {
    [$teamLeader, $booking] = makeCompleteTaskScenario('arrived_dropoff');
    $booking->update(['payment_proof_path' => 'task-photos/fake-proof.jpg']);
    Sanctum::actingAs($teamLeader, ['*']);

    $this->post(completeTaskUrl($booking), [
        'payment_method' => 'gcash',
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertOk()->assertJsonPath('success', true);

    expect($booking->fresh()->status)->toBe('waiting_verification');
});

it('I: bank_transfer without proof fails', function () {
    [$teamLeader, $booking] = makeCompleteTaskScenario('arrived_dropoff');
    Sanctum::actingAs($teamLeader, ['*']);

    $this->post(completeTaskUrl($booking), [
        'payment_method' => 'bank_transfer',
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertStatus(422);

    expect($booking->fresh()->status)->toBe('arrived_dropoff');
});

it('J: bank_transfer with signature + proof succeeds', function () {
    [$teamLeader, $booking] = makeCompleteTaskScenario('arrived_dropoff');
    $booking->update(['payment_proof_path' => 'task-photos/fake-proof.jpg']);
    Sanctum::actingAs($teamLeader, ['*']);

    $this->post(completeTaskUrl($booking), [
        'payment_method' => 'bank_transfer',
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertOk()->assertJsonPath('success', true);

    expect($booking->fresh()->status)->toBe('waiting_verification');
});

it('K: complete() writes payment_submitted_at', function () {
    [$teamLeader, $booking] = makeCompleteTaskScenario('arrived_dropoff');
    $booking->update(['payment_proof_path' => 'task-photos/fake-proof.jpg']);
    Sanctum::actingAs($teamLeader, ['*']);

    $this->post(completeTaskUrl($booking), [
        'payment_method' => 'cash',
        'cash_received' => 4536,
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertOk();

    expect($booking->fresh()->payment_submitted_at)->not->toBeNull();
});

it('L: complete() writes customer_signature_path', function () {
    [$teamLeader, $booking] = makeCompleteTaskScenario('arrived_dropoff');
    $booking->update(['payment_proof_path' => 'task-photos/fake-proof.jpg']);
    Sanctum::actingAs($teamLeader, ['*']);

    $this->post(completeTaskUrl($booking), [
        'payment_method' => 'cash',
        'cash_received' => 4536,
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertOk();

    expect($booking->fresh()->customer_signature_path)->not->toBeNull();
});

it('M: complete() transitions status to waiting_verification', function () {
    [$teamLeader, $booking] = makeCompleteTaskScenario('arrived_dropoff');
    $booking->update(['payment_proof_path' => 'task-photos/fake-proof.jpg']);
    Sanctum::actingAs($teamLeader, ['*']);

    $this->post(completeTaskUrl($booking), [
        'payment_method' => 'cash',
        'cash_received' => 4536,
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertOk();

    expect($booking->fresh()->status)->toBe('waiting_verification');
});

it('N: quotation remains untouched by complete()', function () {
    [$teamLeader, $booking, $quotation] = makeCompleteTaskScenario('arrived_dropoff');
    $booking->update(['payment_proof_path' => 'task-photos/fake-proof.jpg']);
    Sanctum::actingAs($teamLeader, ['*']);

    $this->post(completeTaskUrl($booking), [
        'payment_method' => 'cash',
        'cash_received' => 4536,
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertOk();

    $fresh = $quotation->fresh();
    expect($fresh->status)->toBe('accepted')
        ->and($fresh->version)->toBe(1)
        ->and((float) $fresh->estimated_price)->toBe(4536.0);
});
