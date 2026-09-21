<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

function gpcRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function gpcDispatcher(): User
{
    return User::factory()->create(['role_id' => gpcRole(2, 'Dispatcher')->id, 'status' => 'active']);
}

function gpcCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'GPC Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'gpc-' . uniqid() . '@example.com',
    ]);
}

function gpcTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'GPC Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'status' => 'active',
    ]);
}

function gpcReadyUnit(TruckType $truckType, string $label): array
{
    $leader = User::factory()->create(['role_id' => gpcRole(3, 'Team Leader')->id]);
    Cache::put("teamleader:presence:{$leader->id}", now()->timestamp, now()->addMinutes(2));
    $leader->forceFill(['must_change_password' => false])->save();

    $unit = Unit::create([
        'name' => $label,
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $leader->id,
        'status' => 'available',
    ]);

    return [$unit, $leader];
}

function gpcConfirmedBooking(Customer $customer, TruckType $truckType, string $groupCode, float $finalTotal): Booking
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
        'final_total' => $finalTotal,
        'status' => 'confirmed',
        'service_type' => 'book_now',
        'customer_approved_at' => now(),
        'price_locked_at' => now(),
        'pickup_lat' => 14.7054035,
        'pickup_lng' => 121.0463671,
        'dropoff_lat' => 14.7865449,
        'dropoff_lng' => 121.0749723,
    ]);

    return $booking->fresh(['customer', 'truckType']);
}

function gpcAcceptedGroupQuotation(Customer $customer, TruckType $truckType, array $bookings, float $additionalFee): Quotation
{
    $extraVehicles = collect($bookings)->slice(1)->map(fn (Booking $b) => [
        'booking_id' => $b->id,
        'truck_type_id' => $truckType->id,
        'final_total' => (float) $b->final_total,
    ])->values()->all();

    $estimatedPrice = collect($bookings)->sum(fn (Booking $b) => (float) $b->final_total) + $additionalFee;

    $quotation = Quotation::create([
        'quotation_number' => 'QT-GPC-' . uniqid(),
        'source_booking_id' => $bookings[0]->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'estimated_price' => $estimatedPrice,
        'additional_fee' => $additionalFee,
        'service_type' => 'book_now',
        'status' => 'accepted',
        'extra_vehicles' => $extraVehicles,
    ]);

    foreach ($bookings as $b) {
        $b->update(['quotation_id' => $quotation->id]);
    }

    return $quotation;
}

function gpcProgressToArrivedDropoff(Booking $booking): void
{
    test()->postJson('/api/v1/team-leader/task/' . $booking->booking_code . '/accept')->assertOk();
    foreach (['on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff'] as $status) {
        $payload = ['status' => $status];
        if ($status === 'arrived_pickup') {
            $payload['lat'] = 14.7054035;
            $payload['lng'] = 121.0463671;
        }
        if ($status === 'arrived_dropoff') {
            $payload['lat'] = 14.7865449;
            $payload['lng'] = 121.0749723;
        }
        test()->patchJson('/api/v1/team-leader/task/' . $booking->booking_code . '/status', $payload)->assertOk();
    }
}

it('excludes a partially-cancelled vehicle from group payment readiness, the group total, and the invoice/receipt, while preserving the original quotation', function () {
    $dispatcher = gpcDispatcher();
    $customer = gpcCustomer();
    $truckType = gpcTruckType();
    $groupCode = 'GPC-GROUP-001';

    $bookingA = gpcConfirmedBooking($customer, $truckType, $groupCode, 4658.08);
    $bookingB = gpcConfirmedBooking($customer, $truckType, $groupCode, 3000.00);
    $bookingC = gpcConfirmedBooking($customer, $truckType, $groupCode, 2000.00);
    $quotation = gpcAcceptedGroupQuotation($customer, $truckType, [$bookingA, $bookingB, $bookingC], 500);

    expect((float) $quotation->estimated_price)->toBe(10158.08);

    [$unitA, $leaderA] = gpcReadyUnit($truckType, 'GPC Unit A');
    [$unitB, $leaderB] = gpcReadyUnit($truckType, 'GPC Unit B');

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingA), [
        'action' => 'accept', 'assigned_unit_id' => $unitA->id,
    ])->assertOk();
    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingB), [
        'action' => 'accept', 'assigned_unit_id' => $unitB->id,
    ])->assertOk();

    $cancel = test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $bookingC), [
        'action' => 'reject',
        'rejection_reason' => 'Vehicle no longer needs towing',
    ]);
    $cancel->assertOk()->assertJson(['success' => true]);
    expect($bookingC->fresh()->status)->toBe('cancelled');
    expect(Quotation::where('customer_id', $customer->id)->count())->toBe(1);

    $bookingA->update(['payment_proof_path' => 'task-photos/gpc-proof-a.jpg']);
    $bookingB->update(['payment_proof_path' => 'task-photos/gpc-proof-b.jpg']);

    Sanctum::actingAs($leaderA, ['*']);
    gpcProgressToArrivedDropoff($bookingA);
    Sanctum::actingAs($leaderB, ['*']);
    gpcProgressToArrivedDropoff($bookingB);

    Sanctum::actingAs($leaderA, ['*']);
    $pollA = test()->getJson('/api/v1/team-leader/task');
    $pollA->assertOk()
        ->assertJsonPath('data.group_ready_for_payment', true)
        ->assertJsonPath('data.group_total', 8158.08);
    expect(collect($pollA->json('data.group_vehicle_totals'))->map(fn ($v) => (float) $v)->all())->toBe([3000.0]);

    Sanctum::actingAs($leaderA, ['*']);
    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    $complete = test()->post('/api/v1/team-leader/task/' . $bookingA->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '8158.08',
    ]);
    $complete->assertOk()->assertJsonPath('success', true);

    $invoice = Invoice::where('quotation_id', $quotation->id)->where('is_current', true)->first();
    expect($invoice)->not->toBeNull();
    expect((float) $invoice->total)->toBe(8158.08);
    expect((float) $invoice->additional_fee)->toBe(500.0);

    $confirm = test()->actingAs($dispatcher)->postJson(route('admin.jobs.confirm-payment', $bookingA));
    $confirm->assertOk()->assertJsonPath('success', true);

    $bookingA->refresh();
    $bookingB->refresh();
    $bookingC->refresh();
    expect($bookingA->status)->toBe('completed');
    expect($bookingB->status)->toBe('completed');
    expect($bookingC->status)->toBe('cancelled');

    $quotation->refresh();
    expect($quotation->status)->toBe('accepted');
    expect((float) $quotation->estimated_price)->toBe(10158.08);
    expect((float) $quotation->additional_fee)->toBe(500.0);
    expect($quotation->extra_vehicles)->toHaveCount(2);
    expect((float) collect($quotation->extra_vehicles)->firstWhere('booking_id', $bookingC->id)['final_total'])->toBe(2000.00);
});
