<?php

/*
 * A single towing job can only be submitted for completion/payment from "arrived_dropoff".
 * A refused attempt must leave nothing behind: no invoice, no payment record, no status change.
 * (The group-booking path already worked this way; the single-job path did not.)
 */

use App\Models\AuditLog;
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

const B1_TOTAL = 2091.26;

/** A job in the given status. No invoice exists yet (the invoice is only issued when payment is submitted). */
function b1Scenario(string $status, bool $withProof = true): array
{
    Role::find(3) ?: tap(new Role(['name' => 'Team Leader']), function ($r) { $r->id = 3; $r->save(); });

    $leader = User::factory()->create(['role_id' => 3, 'must_change_password' => false]);
    $truck = TruckType::create(['name' => 'B1 Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);
    $unit = Unit::create([
        'name' => 'B1 Unit ' . fake()->unique()->numerify('##'), 'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truck->id, 'team_leader_id' => $leader->id, 'status' => 'on_job',
    ]);
    $customer = Customer::create(['full_name' => 'B1 Customer', 'phone' => '09171234567', 'email' => 'b1-' . uniqid() . '@example.test']);

    $booking = Booking::create([
        'customer_id' => $customer->id, 'truck_type_id' => $truck->id, 'assigned_unit_id' => $unit->id, 'assigned_team_leader_id' => $leader->id,
        'pickup_address' => 'B1 Pickup', 'pickup_lat' => 14.5995, 'pickup_lng' => 120.9842,
        'dropoff_address' => 'B1 Dropoff', 'dropoff_lat' => 14.6905, 'dropoff_lng' => 120.9842,
        'distance_km' => 10.12, 'base_rate' => 1500, 'per_km_rate' => 60,
        'computed_total' => 1867.20, 'vat_exclusive_total' => 1867.20, 'final_total' => B1_TOTAL,
        'status' => $status, 'assigned_at' => now(),
        'payment_proof_path' => $withProof ? 'task-photos/b1-proof.jpg' : null,
    ]);
    $quotation = Quotation::create([
        'quotation_number' => 'Q-B1-' . uniqid(), 'source_booking_id' => $booking->id, 'customer_id' => $customer->id, 'truck_type_id' => $truck->id,
        'pickup_address' => $booking->pickup_address, 'dropoff_address' => $booking->dropoff_address, 'distance_km' => 10.12,
        'estimated_price' => B1_TOTAL, 'status' => 'accepted',
    ]);
    $booking->update(['quotation_id' => $quotation->id]);

    return [$leader, $booking->fresh(), $unit];
}

function b1Complete(Booking $booking, array $override = [])
{
    return test()->post('/api/v1/team-leader/task/' . $booking->booking_code . '/complete', array_merge([
        'payment_method' => 'cash',
        'cash_received' => B1_TOTAL,
        'signature' => UploadedFile::fake()->image('signature.png'),
    ], $override), ['Accept' => 'application/json']);
}

/** Nothing about the payment or invoice may have changed. */
function b1ExpectUntouched(Booking $booking, string $status): void
{
    $fresh = $booking->fresh();
    expect($fresh->status)->toBe($status);
    expect($fresh->payment_submitted_at)->toBeNull();
    expect($fresh->payment_method)->toBeNull();
    expect($fresh->cash_received)->toBeNull();
    expect($fresh->customer_signature_path)->toBeNull();
    expect($fresh->completion_requested_at)->toBeNull();
    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(0);
    expect(AuditLog::where('action', 'payment_submitted')->where('entity_id', $booking->id)->count())->toBe(0);
}

/* ---------- refused: the job has not reached the drop-off ---------- */

it('refuses completion from every status before the drop-off and leaves no invoice, payment or status change', function (string $status) {
    [$leader, $booking, $unit] = b1Scenario($status);
    Sanctum::actingAs($leader, ['*']);

    b1Complete($booking)->assertStatus(422)->assertJsonPath('success', false);

    b1ExpectUntouched($booking, $status);
    expect($unit->fresh()->status)->toBe('on_job'); // the truck is not released either
})->with(['assigned', 'accepted', 'on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'on_job']);

it('still refuses completion of finished jobs without touching them', function (string $status) {
    [$leader, $booking] = b1Scenario($status);
    Sanctum::actingAs($leader, ['*']);

    b1Complete($booking)->assertStatus(422);

    expect($booking->fresh()->status)->toBe($status);
    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(0);
})->with(['completed', 'cancelled']);

/* ---------- refused at the drop-off for another reason: still nothing is left behind ---------- */

it('does not issue an invoice when the cash received is below the amount due', function () {
    [$leader, $booking] = b1Scenario('arrived_dropoff');
    Sanctum::actingAs($leader, ['*']);

    b1Complete($booking, ['cash_received' => B1_TOTAL - 500])->assertStatus(422)->assertJsonPath('message', 'Cash received must cover the final total.');

    b1ExpectUntouched($booking, 'arrived_dropoff');
});

it('does not issue an invoice when the payment-proof photo is missing', function () {
    [$leader, $booking] = b1Scenario('arrived_dropoff', withProof: false);
    Sanctum::actingAs($leader, ['*']);

    b1Complete($booking)->assertStatus(422)->assertJsonPath('message', 'Payment proof must be uploaded before completing this task.');

    b1ExpectUntouched($booking, 'arrived_dropoff');
});

it('does not issue an invoice when the signature is missing', function () {
    [$leader, $booking] = b1Scenario('arrived_dropoff');
    Sanctum::actingAs($leader, ['*']);

    test()->post('/api/v1/team-leader/task/' . $booking->booking_code . '/complete', ['payment_method' => 'cash', 'cash_received' => B1_TOTAL], ['Accept' => 'application/json'])
        ->assertStatus(422);

    b1ExpectUntouched($booking, 'arrived_dropoff');
});

it('accepts a valid completion after earlier refused attempts, with exactly one invoice', function () {
    [$leader, $booking] = b1Scenario('arrived_dropoff');
    Sanctum::actingAs($leader, ['*']);

    b1Complete($booking, ['cash_received' => 100])->assertStatus(422);
    b1Complete($booking, ['cash_received' => 100])->assertStatus(422);
    b1ExpectUntouched($booking, 'arrived_dropoff');

    b1Complete($booking)->assertOk()->assertJsonPath('success', true);

    expect($booking->fresh()->status)->toBe('waiting_verification');
    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(1);
});

/* ---------- accepted: the correct drop-off completion is unchanged ---------- */

it('completes from arrived_dropoff with cash, proof and signature: invoice, payment record, audit entry and truck release', function () {
    [$leader, $booking, $unit] = b1Scenario('arrived_dropoff');
    Sanctum::actingAs($leader, ['*']);

    b1Complete($booking)->assertOk()->assertJsonPath('success', true);

    $fresh = $booking->fresh();
    expect($fresh->status)->toBe('waiting_verification');
    expect($fresh->payment_method)->toBe('cash');
    expect((float) $fresh->cash_received)->toBe(B1_TOTAL);
    expect($fresh->payment_submitted_at)->not->toBeNull();
    expect($fresh->customer_signature_path)->not->toBeNull();

    $invoices = Invoice::where('booking_id', $booking->id)->get();
    expect($invoices)->toHaveCount(1);
    expect((float) $invoices->first()->total)->toBe(B1_TOTAL);

    expect(AuditLog::where('action', 'payment_submitted')->where('entity_id', $booking->id)->count())->toBe(1);
    expect($unit->fresh()->status)->toBe('available');
});

it('accepts GCash from arrived_dropoff with proof and signature (no cash amount needed)', function () {
    [$leader, $booking] = b1Scenario('arrived_dropoff');
    Sanctum::actingAs($leader, ['*']);

    b1Complete($booking, ['payment_method' => 'gcash', 'cash_received' => null])->assertOk();

    expect($booking->fresh()->status)->toBe('waiting_verification');
    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(1);
});

it('treats a repeated valid completion as already submitted: still one invoice and one audit entry', function () {
    [$leader, $booking] = b1Scenario('arrived_dropoff');
    Sanctum::actingAs($leader, ['*']);

    b1Complete($booking)->assertOk();
    b1Complete($booking)->assertOk()->assertJsonPath('message', 'Task already submitted for dispatcher confirmation.');

    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(1);
    expect(AuditLog::where('action', 'payment_submitted')->where('entity_id', $booking->id)->count())->toBe(1);
});

it('keeps the owner check: another Team Leader cannot complete the job', function () {
    [, $booking] = b1Scenario('arrived_dropoff');
    $other = User::factory()->create(['role_id' => 3, 'must_change_password' => false]);
    Sanctum::actingAs($other, ['*']);

    b1Complete($booking)->assertStatus(403);

    b1ExpectUntouched($booking, 'arrived_dropoff');
});
