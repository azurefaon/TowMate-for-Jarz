<?php

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\Unit;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

function gfcCoords(): array
{
    return [
        'pickup_lat' => 14.7054035,
        'pickup_lng' => 121.0463671,
        'dropoff_lat' => 14.7865449,
        'dropoff_lng' => 121.0749723,
    ];
}

function gfcSetupGroup(string $groupCode): array
{
    $dispatcher = gauDispatcher();
    $customer = gauCustomer();
    $truckType = gauTruckType();

    $bookingA = gauGroupedBooking($customer, $truckType, $groupCode);
    $bookingB = gauGroupedBooking($customer, $truckType, $groupCode);
    $bookingA->update(gfcCoords() + ['payment_proof_path' => 'task-photos/gfc-proof-a.jpg']);
    $bookingB->update(gfcCoords() + ['payment_proof_path' => 'task-photos/gfc-proof-b.jpg']);

    $quotation = gauAcceptedGroupQuotation($customer, $truckType, $bookingA, $bookingB);
    $quotation->update(['additional_fee' => 500]);
    $quotation->update(['estimated_price' => 9816.16]);

    [$unitA, $leaderA] = gauReadyUnit($truckType, 'GFC Unit A');
    [$unitB, $leaderB] = gauReadyUnit($truckType, 'GFC Unit B');
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

    return compact('dispatcher', 'customer', 'truckType', 'bookingA', 'bookingB', 'quotation', 'unitA', 'unitB', 'leaderA', 'leaderB');
}

function jobRowHtml(string $html, string $bookingCode): string
{
    $codePos = strpos($html, 'data-booking-code="' . $bookingCode . '"');
    $trStart = strrpos(substr($html, 0, $codePos), '<tr');
    $trEnd = strpos($html, '</tr>', $codePos);

    return substr($html, $trStart, $trEnd - $trStart);
}

function gfcProgressToArrivedDropoff(Booking $booking): void
{
    $coords = gfcCoords();
    test()->postJson('/api/v1/team-leader/task/' . $booking->booking_code . '/accept')->assertOk();
    foreach (['on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff'] as $status) {
        $payload = ['status' => $status];
        if ($status === 'arrived_pickup') {
            $payload['lat'] = $coords['pickup_lat'];
            $payload['lng'] = $coords['pickup_lng'];
        }
        if ($status === 'arrived_dropoff') {
            $payload['lat'] = $coords['dropoff_lat'];
            $payload['lng'] = $coords['dropoff_lng'];
        }
        test()->patchJson('/api/v1/team-leader/task/' . $booking->booking_code . '/status', $payload)
            ->assertOk();
    }
}

it('lets one grouped vehicle reach arrived_dropoff while the sibling stays untouched', function () {
    $ctx = gfcSetupGroup('GFC-GROUP-001');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    $bBefore = $ctx['bookingB']->fresh()->updated_at;

    gfcProgressToArrivedDropoff($ctx['bookingA']);

    $ctx['bookingA']->refresh();
    expect($ctx['bookingA']->status)->toBe('arrived_dropoff')
        ->and($ctx['bookingA']->assigned_team_leader_id)->toBe($ctx['leaderA']->id);

    $ctx['bookingB']->refresh();
    expect($ctx['bookingB']->status)->toBe('assigned')
        ->and($ctx['bookingB']->assigned_team_leader_id)->toBe($ctx['leaderB']->id)
        ->and($ctx['bookingB']->updated_at->equalTo($bBefore))->toBeTrue();
});

it('exposes 1 of 2 group progress and blocks group payment until both vehicles arrive at drop-off', function () {
    $ctx = gfcSetupGroup('GFC-GROUP-002');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);

    $current = test()->getJson('/api/v1/team-leader/task');
    $current->assertOk()
        ->assertJsonPath('data.status', 'arrived_dropoff')
        ->assertJsonPath('data.group_ready_for_payment', false);

    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    $attempt = test()->post('/api/v1/team-leader/task/' . $ctx['bookingA']->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ]);
    $attempt->assertStatus(422)
        ->assertJsonPath('message', 'Waiting for all vehicles in this group to reach drop-off before payment can be submitted.');

    expect($ctx['bookingA']->fresh()->status)->toBe('arrived_dropoff');
});

it('submits one consolidated group payment covering the full accepted quotation total with the adjustment applied once', function () {
    Mail::fake();
    $ctx = gfcSetupGroup('GFC-GROUP-003');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);

    Sanctum::actingAs($ctx['leaderB'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingB']);

    $readyCheck = test()->getJson('/api/v1/team-leader/task');
    $readyCheck->assertOk()->assertJsonPath('data.group_ready_for_payment', true)
        ->assertJsonPath('data.group_total', 9816.16);

    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    $complete = test()->post('/api/v1/team-leader/task/' . $ctx['bookingB']->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ]);
    $complete->assertOk()->assertJsonPath('success', true);

    $ctx['bookingA']->refresh();
    $ctx['bookingB']->refresh();

    expect($ctx['bookingA']->status)->toBe('waiting_verification')
        ->and($ctx['bookingA']->payment_method)->toBe('cash')
        ->and((float) $ctx['bookingA']->cash_received)->toBe(9816.16)
        ->and($ctx['bookingA']->customer_signature_path)->not->toBeNull();

    expect($ctx['bookingB']->status)->toBe('waiting_verification')
        ->and($ctx['bookingB']->payment_method)->toBe('cash')
        ->and((float) $ctx['bookingB']->cash_received)->toBe(9816.16)
        ->and($ctx['bookingB']->customer_signature_path)->not->toBeNull();

    $invoices = Invoice::where('quotation_id', $ctx['quotation']->id)->get();
    expect($invoices)->toHaveCount(1);
    expect((float) $invoices->first()->total)->toBe(9816.16);
    expect((float) $invoices->first()->additional_fee)->toBe(500.0);
});

it('rejects a grouped cash payment submission with no payment proof on the completing booking', function () {
    $ctx = gfcSetupGroup('GFC-GROUP-PROOF-001');
    $ctx['bookingA']->update(['payment_proof_path' => null]);
    $ctx['bookingB']->update(['payment_proof_path' => null]);

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);

    Sanctum::actingAs($ctx['leaderB'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingB']);

    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    $attempt = test()->post('/api/v1/team-leader/task/' . $ctx['bookingB']->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ]);
    $attempt->assertStatus(422)
        ->assertJsonPath('message', 'Payment proof must be uploaded before completing this task.');

    $ctx['bookingA']->refresh();
    $ctx['bookingB']->refresh();
    expect($ctx['bookingA']->status)->not->toBe('waiting_verification');
    expect($ctx['bookingB']->status)->not->toBe('waiting_verification');
});

it('accepts a grouped cash payment submission with exactly one uploaded proof on the completing booking', function () {
    $ctx = gfcSetupGroup('GFC-GROUP-PROOF-002');
    $ctx['bookingA']->update(['payment_proof_path' => null]);
    $ctx['bookingB']->update(['payment_proof_path' => 'task-photos/gfc-single-proof.jpg']);

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);

    Sanctum::actingAs($ctx['leaderB'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingB']);

    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    $complete = test()->post('/api/v1/team-leader/task/' . $ctx['bookingB']->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ]);
    $complete->assertOk()->assertJsonPath('success', true);

    $ctx['bookingA']->refresh();
    $ctx['bookingB']->refresh();
    expect($ctx['bookingA']->status)->toBe('waiting_verification');
    expect($ctx['bookingB']->status)->toBe('waiting_verification');
});

it('confirms group payment once, marks both bookings completed, frees both units, and sends exactly one receipt email', function () {
    Mail::fake();
    $ctx = gfcSetupGroup('GFC-GROUP-004');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);
    Sanctum::actingAs($ctx['leaderB'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingB']);

    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    test()->post('/api/v1/team-leader/task/' . $ctx['bookingB']->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ])->assertOk();

    $availabilityBefore = app(App\Services\UnitAvailabilityService::class)->evaluate($ctx['unitA']->fresh());
    expect($availabilityBefore['available'])->toBeFalse();

    $confirm = test()->actingAs($ctx['dispatcher'])->postJson(
        route('admin.jobs.confirm-payment', $ctx['bookingA'])
    );
    $confirm->assertOk()->assertJsonPath('success', true);

    $ctx['bookingA']->refresh();
    $ctx['bookingB']->refresh();
    expect($ctx['bookingA']->status)->toBe('completed')
        ->and($ctx['bookingB']->status)->toBe('completed');

    $receipts = Receipt::whereIn('booking_id', [$ctx['bookingA']->id, $ctx['bookingB']->id])->get();
    expect($receipts)->toHaveCount(1);
    expect($receipts->first()->booking_id)->toBe($ctx['quotation']->source_booking_id);

    Mail::assertSent(\App\Mail\BookingReceiptMail::class, 1);

    $unitAFresh = Unit::find($ctx['unitA']->id);
    $unitBFresh = Unit::find($ctx['unitB']->id);
    expect($unitAFresh->status)->toBe('available')
        ->and($unitBFresh->status)->toBe('available');
});

it('never lets one Team Leader complete the sibling booking assigned to another Team Leader', function () {
    $ctx = gfcSetupGroup('GFC-GROUP-005');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);

    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    $response = test()->post('/api/v1/team-leader/task/' . $ctx['bookingB']->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ]);
    $response->assertStatus(403)->assertJsonPath('message', 'This task is not assigned to you.');

    expect($ctx['bookingB']->fresh()->status)->toBe('assigned');
});

it('keeps single-vehicle Team Leader completion behavior unchanged', function () {
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
    ] + gfcCoords());

    $quotation = \App\Models\Quotation::create([
        'quotation_number' => 'QT-GFC-SOLO-' . uniqid(),
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

    [$unit, $leader] = gauReadyUnit($truckType, 'GFC Solo Unit');
    $leader->forceFill(['must_change_password' => false])->save();

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $booking), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
    ])->assertOk();

    Sanctum::actingAs($leader, ['*']);
    gfcProgressToArrivedDropoff($booking);

    expect($booking->fresh()->status)->toBe('arrived_dropoff');
    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(1);

    $booking->update(['payment_proof_path' => 'task-photos/gfc-solo-proof.jpg']);
    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    $complete = test()->post('/api/v1/team-leader/task/' . $booking->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '4658.08',
    ]);
    $complete->assertOk()->assertJsonPath('success', true);

    expect($booking->fresh()->status)->toBe('waiting_verification');
});

it('keeps legacy embedded-extra grouped data backward-compatible via the original sibling auto-claim', function () {
    $dispatcher = gauDispatcher();
    $customer = gauCustomer();
    $truckType = gauTruckType();
    $groupCode = 'GFC-LEGACY-001';

    $primary = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'group_code' => $groupCode,
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
    ] + gfcCoords());

    $sibling = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'group_code' => $groupCode,
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
    ] + gfcCoords());

    $quotation = \App\Models\Quotation::create([
        'quotation_number' => 'QT-GFC-LEGACY-' . uniqid(),
        'source_booking_id' => $primary->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'estimated_price' => 4658.08,
        'service_type' => 'book_now',
        'status' => 'accepted',
        'extra_vehicles' => [
            ['truck_type_id' => $truckType->id, 'final_total' => 4658.08],
        ],
    ]);
    $primary->update(['quotation_id' => $quotation->id]);

    [$unit, $leader] = gauReadyUnit($truckType, 'GFC Legacy Unit');
    $leader->forceFill(['must_change_password' => false])->save();

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $primary), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
    ])->assertOk();

    Sanctum::actingAs($leader, ['*']);
    gfcProgressToArrivedDropoff($primary);

    $primary->update(['payment_proof_path' => 'task-photos/gfc-legacy-proof.jpg']);
    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    $complete = test()->post('/api/v1/team-leader/task/' . $primary->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '4658.08',
    ]);
    $complete->assertOk()->assertJsonPath('success', true);

    expect($primary->fresh()->status)->toBe('waiting_verification');

    $sibling->refresh();
    expect($sibling->assigned_team_leader_id)->toBe($leader->id)
        ->and($sibling->status)->toBe('accepted');
});

it('never reassigns an independently-assigned normalized sibling through the legacy auto-claim path', function () {
    $ctx = gfcSetupGroup('GFC-GROUP-006');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);

    $bBefore = $ctx['bookingB']->fresh();
    expect($bBefore->assigned_team_leader_id)->toBe($ctx['leaderB']->id)
        ->and($bBefore->status)->toBe('assigned');
});

it('flips group_ready_for_payment to true on both team leaders current-task responses as soon as the second vehicle reaches drop-off', function () {
    $ctx = gfcSetupGroup('GFC-GROUP-008');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);

    $pollA = test()->getJson('/api/v1/team-leader/task');
    $pollA->assertOk()->assertJsonPath('data.group_ready_for_payment', false);

    Sanctum::actingAs($ctx['leaderB'], ['*']);
    $pollBBefore = test()->getJson('/api/v1/team-leader/task');
    $pollBBefore->assertOk()->assertJsonPath('data.group_ready_for_payment', false);

    gfcProgressToArrivedDropoff($ctx['bookingB']);

    $pollBAfter = test()->getJson('/api/v1/team-leader/task');
    $pollBAfter->assertOk()
        ->assertJsonPath('data.group_ready_for_payment', true)
        ->assertJsonPath('data.group_total', 9816.16);

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    $pollAAfter = test()->getJson('/api/v1/team-leader/task');
    $pollAAfter->assertOk()
        ->assertJsonPath('data.group_ready_for_payment', true)
        ->assertJsonPath('data.group_total', 9816.16);
});

it('treats a retried group payment submission for the same booking as already submitted without creating a second invoice', function () {
    $ctx = gfcSetupGroup('GFC-GROUP-009');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);
    Sanctum::actingAs($ctx['leaderB'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingB']);

    $firstSignature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    $first = test()->post('/api/v1/team-leader/task/' . $ctx['bookingB']->booking_code . '/complete', [
        'signature' => $firstSignature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ]);
    $first->assertOk()->assertJsonPath('success', true);

    $retrySignature = \Illuminate\Http\UploadedFile::fake()->image('sig2.png');
    $retry = test()->post('/api/v1/team-leader/task/' . $ctx['bookingB']->booking_code . '/complete', [
        'signature' => $retrySignature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ]);
    $retry->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Task already submitted for dispatcher confirmation.');

    expect(Invoice::where('quotation_id', $ctx['quotation']->id)->count())->toBe(1);
});

it('prevents a duplicate group invoice when both team leaders submit payment for their own vehicle back to back', function () {
    $ctx = gfcSetupGroup('GFC-GROUP-010');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);
    Sanctum::actingAs($ctx['leaderB'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingB']);

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    $signatureA = \Illuminate\Http\UploadedFile::fake()->image('sig-a.png');
    $fromA = test()->post('/api/v1/team-leader/task/' . $ctx['bookingA']->booking_code . '/complete', [
        'signature' => $signatureA,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ]);
    $fromA->assertOk()->assertJsonPath('success', true);

    Sanctum::actingAs($ctx['leaderB'], ['*']);
    $signatureB = \Illuminate\Http\UploadedFile::fake()->image('sig-b.png');
    $fromB = test()->post('/api/v1/team-leader/task/' . $ctx['bookingB']->booking_code . '/complete', [
        'signature' => $signatureB,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ]);
    $fromB->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Task already submitted for dispatcher confirmation.');

    $invoices = Invoice::where('quotation_id', $ctx['quotation']->id)->get();
    expect($invoices)->toHaveCount(1);
    expect((float) $invoices->first()->total)->toBe(9816.16);
    expect((float) $invoices->first()->additional_fee)->toBe(500.0);

    expect(App\Models\AuditLog::where('action', 'group_payment_submitted')
        ->where('reference', $ctx['bookingA']->booking_code)
        ->count())->toBe(1);

    $ctx['bookingA']->refresh();
    $ctx['bookingB']->refresh();
    expect($ctx['bookingA']->status)->toBe('waiting_verification')
        ->and($ctx['bookingB']->status)->toBe('waiting_verification');
});

it('supersedes a stale non-group current invoice on the anchor booking with the correct consolidated group total', function () {
    $ctx = gfcSetupGroup('GFC-GROUP-011');

    $staleInvoice = Invoice::create([
        'booking_id'     => $ctx['bookingA']->id,
        'quotation_id'   => $ctx['quotation']->id,
        'subtotal'       => 4159.00,
        'additional_fee' => 0,
        'discount'       => 0,
        'total'          => 4658.08,
        'status'         => 'issued',
        'is_current'     => true,
        'created_by'     => $ctx['leaderA']->id,
    ]);

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);
    Sanctum::actingAs($ctx['leaderB'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingB']);

    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    $complete = test()->post('/api/v1/team-leader/task/' . $ctx['bookingB']->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ]);
    $complete->assertOk()->assertJsonPath('success', true);

    $staleInvoice->refresh();
    expect($staleInvoice->is_current)->toBeFalse()
        ->and($staleInvoice->status)->toBe('voided');

    $currentInvoices = Invoice::where('quotation_id', $ctx['quotation']->id)->where('is_current', true)->get();
    expect($currentInvoices)->toHaveCount(1);
    expect((float) $currentInvoices->first()->total)->toBe(9816.16);
    expect((float) $currentInvoices->first()->additional_fee)->toBe(500.0);
    expect($currentInvoices->first()->previous_invoice_id)->toBe($staleInvoice->id);
});

it('exposes per-vehicle totals and the adjustment exactly once alongside the consolidated group total', function () {
    $ctx = gfcSetupGroup('GFC-GROUP-012');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);
    Sanctum::actingAs($ctx['leaderB'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingB']);

    $current = test()->getJson('/api/v1/team-leader/task');
    $current->assertOk()
        ->assertJsonPath('data.group_ready_for_payment', true)
        ->assertJsonPath('data.group_total', 9816.16)
        ->assertJsonPath('data.group_adjustment', 500)
        ->assertJsonPath('data.group_vehicle_totals', [4658.08, 4658.08]);

    $payload = $current->json('data');
    expect(array_sum($payload['group_vehicle_totals']) + $payload['group_adjustment'])
        ->toBe($payload['group_total']);
});

it('shows the consolidated group total, payment method, and single adjustment to the submitting team leader after group payment', function () {
    $ctx = gfcSetupGroup('GFC-GROUP-013');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);
    Sanctum::actingAs($ctx['leaderB'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingB']);

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    test()->post('/api/v1/team-leader/task/' . $ctx['bookingA']->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ])->assertOk();

    $current = test()->getJson('/api/v1/team-leader/task');
    $current->assertOk()
        ->assertJsonPath('data.booking_code', $ctx['bookingA']->booking_code)
        ->assertJsonPath('data.status', 'waiting_verification')
        ->assertJsonPath('data.payment_method', 'cash')
        ->assertJsonPath('data.group_total', 9816.16)
        ->assertJsonPath('data.group_adjustment', 500)
        ->assertJsonPath('data.group_vehicle_totals', [4658.08, 4658.08]);

    expect((float) $ctx['bookingA']->fresh()->cash_received)->toBe(9816.16);

    $invoices = Invoice::where('quotation_id', $ctx['quotation']->id)->where('is_current', true)->get();
    expect($invoices)->toHaveCount(1);
    expect((float) $invoices->first()->total)->toBe(9816.16);
    expect((float) $invoices->first()->additional_fee)->toBe(500.0);

    expect($ctx['bookingA']->fresh()->status)->toBe('waiting_verification');
    expect(Receipt::where('booking_id', $ctx['bookingA']->id)->count())->toBe(0);
});

it('lets Team Leader B immediately see the group payment already collected without submitting again', function () {
    $ctx = gfcSetupGroup('GFC-GROUP-014');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);
    Sanctum::actingAs($ctx['leaderB'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingB']);

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    test()->post('/api/v1/team-leader/task/' . $ctx['bookingA']->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ])->assertOk();

    Sanctum::actingAs($ctx['leaderB'], ['*']);
    $currentB = test()->getJson('/api/v1/team-leader/task');
    $currentB->assertOk()
        ->assertJsonPath('data.booking_code', $ctx['bookingB']->booking_code)
        ->assertJsonPath('data.status', 'waiting_verification')
        ->assertJsonPath('data.payment_method', 'cash')
        ->assertJsonPath('data.group_total', 9816.16);

    expect((float) $ctx['bookingB']->fresh()->cash_received)->toBe(9816.16)
        ->and($ctx['bookingB']->fresh()->payment_submitted_at)->not->toBeNull()
        ->and($ctx['bookingB']->fresh()->customer_signature_path)
        ->toBe($ctx['bookingA']->fresh()->customer_signature_path);
});

it('rejects Team Leader B from creating a second payment, signature, or invoice for the same group', function () {
    $ctx = gfcSetupGroup('GFC-GROUP-015');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);
    Sanctum::actingAs($ctx['leaderB'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingB']);

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    $sigA = \Illuminate\Http\UploadedFile::fake()->image('sig-a.png');
    test()->post('/api/v1/team-leader/task/' . $ctx['bookingA']->booking_code . '/complete', [
        'signature' => $sigA,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ])->assertOk();

    $originalSignaturePath = $ctx['bookingA']->fresh()->customer_signature_path;

    Sanctum::actingAs($ctx['leaderB'], ['*']);
    $sigB = \Illuminate\Http\UploadedFile::fake()->image('sig-b.png');
    $second = test()->post('/api/v1/team-leader/task/' . $ctx['bookingB']->booking_code . '/complete', [
        'signature' => $sigB,
        'payment_method' => 'gcash',
    ]);
    $second->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Task already submitted for dispatcher confirmation.');

    $ctx['bookingA']->refresh();
    $ctx['bookingB']->refresh();
    expect($ctx['bookingA']->customer_signature_path)->toBe($originalSignaturePath)
        ->and($ctx['bookingB']->customer_signature_path)->toBe($originalSignaturePath)
        ->and($ctx['bookingA']->payment_method)->toBe('cash')
        ->and($ctx['bookingB']->payment_method)->toBe('cash')
        ->and((float) $ctx['bookingB']->cash_received)->toBe(9816.16);

    expect(Invoice::where('quotation_id', $ctx['quotation']->id)->count())->toBe(1);
    expect(App\Models\AuditLog::where('action', 'group_payment_submitted')
        ->where('reference', $ctx['bookingA']->booking_code)
        ->count())->toBe(1);
});

it('keeps a solo booking current-task response using its own final_total after payment submission', function () {
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
    ] + gfcCoords());

    $quotation = \App\Models\Quotation::create([
        'quotation_number' => 'QT-GFC-SOLO2-' . uniqid(),
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

    [$unit, $leader] = gauReadyUnit($truckType, 'GFC Solo Submitted Unit');
    $leader->forceFill(['must_change_password' => false])->save();

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $booking), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
    ])->assertOk();

    Sanctum::actingAs($leader, ['*']);
    gfcProgressToArrivedDropoff($booking);

    $booking->update(['payment_proof_path' => 'task-photos/gfc-solo2-proof.jpg']);
    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    test()->post('/api/v1/team-leader/task/' . $booking->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '4658.08',
    ])->assertOk();

    $current = test()->getJson('/api/v1/team-leader/task');
    $current->assertOk()
        ->assertJsonPath('data.status', 'waiting_verification')
        ->assertJsonPath('data.payment_method', 'cash')
        ->assertJsonPath('data.final_total', 4658.08)
        ->assertJsonPath('data.group_total', null)
        ->assertJsonPath('data.group_vehicle_count', 1);

    expect(Invoice::where('booking_id', $booking->id)->count())->toBe(1);
});

it('shows the consolidated group total as Amount Due and Amount Submitted in the Active Jobs drawer', function () {
    $ctx = gfcSetupGroup('GFC-GROUP-016');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);
    Sanctum::actingAs($ctx['leaderB'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingB']);

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    test()->post('/api/v1/team-leader/task/' . $ctx['bookingA']->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ])->assertOk();

    $html = test()->actingAs($ctx['dispatcher'])
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    $row = jobRowHtml($html, $ctx['bookingA']->booking_code);
    expect($row)->toContain('data-total="9,816.16"');
    expect($row)->toContain('data-amount-submitted="9,816.16"');
    expect($row)->not->toContain('data-total="4,658.08"');
});

it('keeps the Active Jobs drawer amount due as the booking own final_total for solo and legacy grouped bookings', function () {
    $dispatcher = gauDispatcher();
    $customer = gauCustomer();
    $truckType = gauTruckType();

    $solo = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'final_total' => 4658.08,
        'status' => 'waiting_verification',
        'service_type' => 'book_now',
        'payment_method' => 'cash',
        'cash_received' => 4658.08,
        'payment_submitted_at' => now(),
        'completion_requested_at' => now(),
        'customer_approved_at' => now(),
        'price_locked_at' => now(),
    ] + gfcCoords());

    $html = test()->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    $row = jobRowHtml($html, $solo->booking_code);
    expect($row)->toContain('data-total="4,658.08"');
});

it('confirms the consolidated group payment once, completes both vehicles, and stays idempotent on a retried confirmation', function () {
    Mail::fake();
    $ctx = gfcSetupGroup('GFC-GROUP-017');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);
    Sanctum::actingAs($ctx['leaderB'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingB']);

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    test()->post('/api/v1/team-leader/task/' . $ctx['bookingA']->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ])->assertOk();

    $confirm = test()->actingAs($ctx['dispatcher'])->postJson(
        route('admin.jobs.confirm-payment', $ctx['bookingA'])
    );
    $confirm->assertOk()->assertJsonPath('success', true);

    $ctx['bookingA']->refresh();
    $ctx['bookingB']->refresh();
    expect($ctx['bookingA']->status)->toBe('completed')
        ->and($ctx['bookingB']->status)->toBe('completed')
        ->and((float) $ctx['bookingA']->final_total)->toBe(4658.08)
        ->and((float) $ctx['bookingB']->final_total)->toBe(4658.08);

    expect((float) $ctx['quotation']->fresh()->estimated_price)->toBe(9816.16);

    $currentInvoices = Invoice::where('quotation_id', $ctx['quotation']->id)->where('is_current', true)->get();
    expect($currentInvoices)->toHaveCount(1);
    expect((float) $currentInvoices->first()->total)->toBe(9816.16);
    expect((float) $currentInvoices->first()->additional_fee)->toBe(500.0);

    $receipts = Receipt::whereIn('booking_id', [$ctx['bookingA']->id, $ctx['bookingB']->id])->get();
    expect($receipts)->toHaveCount(1);
    expect($receipts->first()->booking_id)->toBe($ctx['quotation']->source_booking_id);
    expect($receipts->first()->invoice_id)->toBe($currentInvoices->first()->id);

    Mail::assertSent(\App\Mail\BookingReceiptMail::class, 1);

    $retry = test()->actingAs($ctx['dispatcher'])->postJson(
        route('admin.jobs.confirm-payment', $ctx['bookingA'])
    );
    $retry->assertOk()->assertJsonPath('message', 'This job was already confirmed.');

    expect(Invoice::where('quotation_id', $ctx['quotation']->id)->count())->toBe(1);
    expect(Receipt::whereIn('booking_id', [$ctx['bookingA']->id, $ctx['bookingB']->id])->count())->toBe(1);
    Mail::assertSent(\App\Mail\BookingReceiptMail::class, 1);

    $retryB = test()->actingAs($ctx['dispatcher'])->postJson(
        route('admin.jobs.confirm-payment', $ctx['bookingB'])
    );
    $retryB->assertOk()->assertJsonPath('message', 'This job was already confirmed.');
});

it('keeps Vehicle 2 current-task and availability correct at every step while Vehicle 1 waits at arrived_dropoff', function () {
    $ctx = gfcSetupGroup('GFC-GROUP-007');
    $coords = gfcCoords();

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);

    $ctx['bookingA']->refresh();
    expect($ctx['bookingA']->status)->toBe('arrived_dropoff');

    Sanctum::actingAs($ctx['leaderB'], ['*']);
    test()->postJson('/api/v1/team-leader/task/' . $ctx['bookingB']->booking_code . '/accept')->assertOk();

    foreach (['on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'on_job'] as $status) {
        $payload = ['status' => $status];
        if ($status === 'arrived_pickup') {
            $payload['lat'] = $coords['pickup_lat'];
            $payload['lng'] = $coords['pickup_lng'];
        }
        test()->patchJson('/api/v1/team-leader/task/' . $ctx['bookingB']->booking_code . '/status', $payload)
            ->assertOk();

        $current = test()->getJson('/api/v1/team-leader/task');
        $current->assertOk()
            ->assertJsonPath('data.booking_code', $ctx['bookingB']->booking_code)
            ->assertJsonPath('data.status', $status)
            ->assertJsonPath('data.group_ready_for_payment', false);

        $availability = app(App\Services\TeamLeaderAvailabilityService::class)->busyTeamLeaderIds();
        expect($availability->contains($ctx['leaderA']->id))->toBeTrue()
            ->and($availability->contains($ctx['leaderB']->id))->toBeTrue();

        $unitA = app(App\Services\UnitAvailabilityService::class)->evaluate($ctx['unitA']->fresh());
        $unitB = app(App\Services\UnitAvailabilityService::class)->evaluate($ctx['unitB']->fresh());
        expect($unitA['available'])->toBeFalse()
            ->and($unitB['available'])->toBeFalse();
    }

    $ctx['bookingA']->refresh();
    expect($ctx['bookingA']->status)->toBe('arrived_dropoff')
        ->and($ctx['bookingA']->assigned_team_leader_id)->toBe($ctx['leaderA']->id);

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    $currentA = test()->getJson('/api/v1/team-leader/task');
    $currentA->assertOk()
        ->assertJsonPath('data.booking_code', $ctx['bookingA']->booking_code)
        ->assertJsonPath('data.status', 'arrived_dropoff')
        ->assertJsonPath('data.group_ready_for_payment', false);
});

it('emails exactly one consolidated group invoice with each vehicle price and the adjustment applied once', function () {
    Mail::fake();
    $ctx = gfcSetupGroup('GFC-GROUP-018');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);
    Sanctum::actingAs($ctx['leaderB'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingB']);

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    test()->post('/api/v1/team-leader/task/' . $ctx['bookingA']->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ])->assertOk();

    Mail::assertSent(\App\Mail\InvoiceMail::class, 1);
    Mail::assertSent(\App\Mail\InvoiceMail::class, function ($mail) {
        return (float) $mail->invoice->total === 9816.16
            && count($mail->groupVehicles) === 2
            && (float) $mail->groupVehicles[0]['final_total'] === 4658.08
            && (float) $mail->groupVehicles[1]['final_total'] === 4658.08
            && (float) $mail->groupAdjustment === 500.0;
    });

    $invoice = Invoice::where('quotation_id', $ctx['quotation']->id)->where('is_current', true)->first();
    expect($invoice->email_sent)->toBeTrue();
});

it('does not send a duplicate group invoice email when the group payment submission is retried', function () {
    Mail::fake();
    $ctx = gfcSetupGroup('GFC-GROUP-019');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);
    Sanctum::actingAs($ctx['leaderB'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingB']);

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    test()->post('/api/v1/team-leader/task/' . $ctx['bookingA']->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ])->assertOk();

    Mail::assertSent(\App\Mail\InvoiceMail::class, 1);

    $retrySignature = \Illuminate\Http\UploadedFile::fake()->image('sig2.png');
    test()->post('/api/v1/team-leader/task/' . $ctx['bookingA']->booking_code . '/complete', [
        'signature' => $retrySignature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ])->assertOk();

    Mail::assertSent(\App\Mail\InvoiceMail::class, 1);
});

it('does not alter the invoice email or PDF for a solo booking', function () {
    Mail::fake();
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
    ] + gfcCoords());

    $quotation = \App\Models\Quotation::create([
        'quotation_number' => 'QT-GFC-SOLOINV-' . uniqid(),
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

    [$unit, $leader] = gauReadyUnit($truckType, 'GFC Solo Invoice Unit');
    $leader->forceFill(['must_change_password' => false])->save();

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $booking), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
    ])->assertOk();

    Sanctum::actingAs($leader, ['*']);
    gfcProgressToArrivedDropoff($booking);

    Mail::assertSent(\App\Mail\InvoiceMail::class, 1);
    Mail::assertSent(\App\Mail\InvoiceMail::class, function ($mail) {
        return empty($mail->groupVehicles)
            && (float) $mail->groupAdjustment === 0.0
            && (float) $mail->invoice->total === 4658.08;
    });
});

it('renders the consolidated group invoice PDF template with each vehicle listed and the adjustment applied once', function () {
    $ctx = gfcSetupGroup('GFC-GROUP-020');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);
    Sanctum::actingAs($ctx['leaderB'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingB']);

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    test()->post('/api/v1/team-leader/task/' . $ctx['bookingA']->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ])->assertOk();

    $invoice = Invoice::where('quotation_id', $ctx['quotation']->id)->where('is_current', true)->first();
    $invoice->load(['booking.customer', 'booking.truckType']);

    $groupVehicles = collect($ctx['quotation']->extra_vehicles)->map(fn($ev) => [
        'truck_type_name' => $ev['truck_type_name'] ?? 'Towing Service',
        'final_total' => (float) $ev['final_total'],
    ])->values()->all();
    $groupAdjustment = (float) $ctx['quotation']->additional_fee;

    $html = view('documents.invoice', [
        'booking' => $invoice->booking,
        'invoice' => $invoice,
        'settings' => app(App\Services\DocumentGenerationService::class)->documentSettings(),
        'generatedAt' => now(),
        'groupVehicles' => $groupVehicles,
        'groupAdjustment' => $groupAdjustment,
        'groupTotal' => 9816.16,
    ])->render();

    expect($html)->toContain('Vehicle 1')
        ->and($html)->toContain('Vehicle 2')
        ->and(substr_count($html, '4,658.08'))->toBe(2)
        ->and($html)->toContain('9,816.16');
});

it('renders the consolidated group receipt email with each vehicle, the adjustment once, and the group total', function () {
    $ctx = gfcSetupGroup('GFC-GROUP-021');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);
    Sanctum::actingAs($ctx['leaderB'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingB']);

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    test()->post('/api/v1/team-leader/task/' . $ctx['bookingA']->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ])->assertOk();

    $groupVehicles = collect($ctx['quotation']->extra_vehicles)->map(fn($ev) => [
        'truck_type_name' => $ev['truck_type_name'] ?? 'Towing Service',
        'final_total' => (float) $ev['final_total'],
    ])->values()->all();
    $groupAdjustment = (float) $ctx['quotation']->additional_fee;

    $mail = new \App\Mail\BookingReceiptMail(
        $ctx['bookingA']->fresh(['customer', 'truckType', 'receipt', 'unit.teamLeader', 'unit.driver']),
        $groupVehicles,
        $groupAdjustment
    );
    $html = $mail->render();

    expect($html)->toContain('Vehicle 1')
        ->and($html)->toContain('Vehicle 2')
        ->and(substr_count($html, '4,658.08'))->toBe(2)
        ->and($html)->toContain('9,816.16')
        ->and($html)->toContain('Cash');
});

it('sends exactly one grouped receipt email through the real dispatcher confirmation flow', function () {
    Mail::fake();
    $ctx = gfcSetupGroup('GFC-GROUP-022');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);
    Sanctum::actingAs($ctx['leaderB'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingB']);

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    test()->post('/api/v1/team-leader/task/' . $ctx['bookingA']->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ])->assertOk();

    test()->actingAs($ctx['dispatcher'])->postJson(
        route('admin.jobs.confirm-payment', $ctx['bookingA'])
    )->assertOk();

    Mail::assertSent(\App\Mail\BookingReceiptMail::class, 1);
    Mail::assertSent(\App\Mail\BookingReceiptMail::class, function ($mail) {
        return count($mail->groupVehicles) === 2 && (float) $mail->groupAdjustment === 500.0;
    });
});

it('shows Back to Home only, with no Next Vehicle prompt, on both completed screens for a normalized group', function () {
    $ctx = gfcSetupGroup('GFC-GROUP-023');

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingA']);
    Sanctum::actingAs($ctx['leaderB'], ['*']);
    gfcProgressToArrivedDropoff($ctx['bookingB']);

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    test()->post('/api/v1/team-leader/task/' . $ctx['bookingA']->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '9816.16',
    ])->assertOk();

    test()->actingAs($ctx['dispatcher'])->postJson(
        route('admin.jobs.confirm-payment', $ctx['bookingA'])
    )->assertOk();

    Sanctum::actingAs($ctx['leaderA'], ['*']);
    $currentA = test()->getJson('/api/v1/team-leader/task');
    $currentA->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.has_claimable_sibling', false);

    Sanctum::actingAs($ctx['leaderB'], ['*']);
    $currentB = test()->getJson('/api/v1/team-leader/task');
    $currentB->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.has_claimable_sibling', false);
});

it('still allows the legacy sequential-claim flow to see the next unclaimed sibling', function () {
    $dispatcher = gauDispatcher();
    $customer = gauCustomer();
    $truckType = gauTruckType();
    $groupCode = 'GFC-LEGACY-CLAIM-001';

    $primary = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'group_code' => $groupCode,
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
    ] + gfcCoords());

    $sibling = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'group_code' => $groupCode,
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
    ] + gfcCoords());

    $quotation = \App\Models\Quotation::create([
        'quotation_number' => 'QT-GFC-LEGACYCLAIM-' . uniqid(),
        'source_booking_id' => $primary->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'estimated_price' => 4658.08,
        'service_type' => 'book_now',
        'status' => 'accepted',
        'extra_vehicles' => [
            ['truck_type_id' => $truckType->id, 'final_total' => 4658.08],
        ],
    ]);
    $primary->update(['quotation_id' => $quotation->id]);

    [$unit, $leader] = gauReadyUnit($truckType, 'GFC Legacy Claim Unit');
    $leader->forceFill(['must_change_password' => false])->save();

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $primary), [
        'action' => 'accept',
        'assigned_unit_id' => $unit->id,
    ])->assertOk();

    $sibling->update(['assigned_team_leader_id' => null, 'status' => 'confirmed']);

    Sanctum::actingAs($leader, ['*']);
    $current = test()->getJson('/api/v1/team-leader/task');
    $current->assertOk()->assertJsonPath('data.has_claimable_sibling', true);
});
