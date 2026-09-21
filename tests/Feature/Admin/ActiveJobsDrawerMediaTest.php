<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function ajdmRoles(): void
{
    DB::table('roles')->insertOrIgnore([
        ['id' => 1, 'name' => 'Owner', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'Admin', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'name' => 'Team Leader', 'created_at' => now(), 'updated_at' => now()],
    ]);
}

function ajdmDispatcher(): User
{
    return User::factory()->create(['role_id' => 2]);
}

function ajdmTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'AJDM Truck ' . uniqid(),
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'max_tonnage' => 5,
    ]);
}

function ajdmCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'AJDM Customer',
        'age' => 30,
        'phone' => '09171234567',
        'email' => 'ajdm-' . uniqid() . '@example.test',
    ]);
}

function ajdmGroupedBooking(Customer $customer, TruckType $truckType, string $groupCode, Unit $unit, array $overrides = []): Booking
{
    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'group_code' => $groupCode,
        'pickup_address' => 'Quezon City Circle',
        'dropoff_address' => 'SM Megamall, Mandaluyong',
        'distance_km' => 6,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1950,
        'final_total' => 1950,
        'status' => 'waiting_verification',
    ], $overrides));
}

it('resolves payment proof and signature from whichever sibling booking actually holds them, not just the anchor', function () {
    ajdmRoles();
    $dispatcher = ajdmDispatcher();
    $truckType = ajdmTruckType();
    $customer = ajdmCustomer();
    $groupCode = 'AJDM-GRP-' . uniqid();

    $unitA = Unit::create(['name' => 'AJDM Unit A', 'plate_number' => 'AJA-' . rand(1000, 9999), 'truck_type_id' => $truckType->id, 'status' => 'on_job']);
    $unitB = Unit::create(['name' => 'AJDM Unit B', 'plate_number' => 'AJB-' . rand(1000, 9999), 'truck_type_id' => $truckType->id, 'status' => 'on_job']);

    $anchor = ajdmGroupedBooking($customer, $truckType, $groupCode, $unitA, [
        'payment_proof_path' => null,
        'customer_signature_path' => null,
        'vehicle_image_path' => json_encode(['vehicle-photos/anchor-1.jpg']),
    ]);
    $sibling = ajdmGroupedBooking($customer, $truckType, $groupCode, $unitB, [
        'payment_proof_path' => 'task-photos/sibling-proof.jpg',
        'customer_signature_path' => 'signatures/sibling-sig.png',
        'vehicle_image_path' => json_encode(['vehicle-photos/sibling-1.jpg', 'vehicle-photos/sibling-2.jpg']),
    ]);

    $quotation = Quotation::create([
        'quotation_number' => 'Q-AJDM-' . uniqid(),
        'source_booking_id' => $anchor->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => $anchor->pickup_address,
        'dropoff_address' => $anchor->dropoff_address,
        'distance_km' => 6,
        'estimated_price' => 3900,
        'status' => 'accepted',
        'extra_vehicles' => [
            ['booking_id' => $anchor->id, 'truck_type_name' => $truckType->name, 'final_total' => 1950],
            ['booking_id' => $sibling->id, 'truck_type_name' => $truckType->name, 'final_total' => 1950],
        ],
    ]);
    $anchor->update(['quotation_id' => $quotation->id]);
    $sibling->update(['quotation_id' => $quotation->id]);

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    expect($anchor->id)->toBeLessThan($sibling->id);

    $rowStart = strpos($html, 'data-booking-code="' . $anchor->booking_code . '"');
    expect($rowStart)->not->toBeFalse();
    $rowEnd = strpos($html, '</tr>', $rowStart);
    $row = substr($html, $rowStart, $rowEnd - $rowStart);

    expect($row)->toContain('data-proof-url="');
    expect($row)->not->toContain('data-proof-url=""');
    expect($row)->toContain('sibling-proof.jpg');

    expect($row)->toContain('data-signature-url="');
    expect($row)->not->toContain('data-signature-url=""');
    expect($row)->toContain('sibling-sig.png');

    expect($row)->toContain('vehicle-photos\/anchor-1.jpg');
    expect($row)->toContain('vehicle-photos\/sibling-1.jpg');
    expect($row)->toContain('vehicle-photos\/sibling-2.jpg');
});

it('still resolves proof, signature, and images correctly for a solo (non-grouped) booking', function () {
    ajdmRoles();
    $dispatcher = ajdmDispatcher();
    $truckType = ajdmTruckType();
    $customer = ajdmCustomer();
    $unit = Unit::create(['name' => 'AJDM Solo Unit', 'plate_number' => 'AJS-' . rand(1000, 9999), 'truck_type_id' => $truckType->id, 'status' => 'on_job']);

    $solo = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'pickup_address' => 'Quezon City Circle',
        'dropoff_address' => 'SM Megamall, Mandaluyong',
        'distance_km' => 6,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1950,
        'final_total' => 1950,
        'status' => 'waiting_verification',
        'payment_proof_path' => 'task-photos/solo-proof.jpg',
        'customer_signature_path' => 'signatures/solo-sig.png',
        'vehicle_image_path' => json_encode(['vehicle-photos/solo-1.jpg']),
    ]);

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    $rowStart = strpos($html, 'data-booking-code="' . $solo->booking_code . '"');
    expect($rowStart)->not->toBeFalse();
    $rowEnd = strpos($html, '</tr>', $rowStart);
    $row = substr($html, $rowStart, $rowEnd - $rowStart);

    expect($row)->toContain('solo-proof.jpg');
    expect($row)->toContain('solo-sig.png');
    expect($row)->toContain('vehicle-photos\/solo-1.jpg');
});

it('leaves data-vehicle-images empty when no sibling has any stored vehicle image', function () {
    ajdmRoles();
    $dispatcher = ajdmDispatcher();
    $truckType = ajdmTruckType();
    $customer = ajdmCustomer();
    $unit = Unit::create(['name' => 'AJDM Empty Unit', 'plate_number' => 'AJE-' . rand(1000, 9999), 'truck_type_id' => $truckType->id, 'status' => 'on_job']);

    $solo = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'pickup_address' => 'Quezon City Circle',
        'dropoff_address' => 'SM Megamall, Mandaluyong',
        'distance_km' => 6,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1950,
        'final_total' => 1950,
        'status' => 'waiting_verification',
    ]);

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->getContent();

    $rowStart = strpos($html, 'data-booking-code="' . $solo->booking_code . '"');
    expect($rowStart)->not->toBeFalse();
    $rowEnd = strpos($html, '</tr>', $rowStart);
    $row = substr($html, $rowStart, $rowEnd - $rowStart);

    expect($row)->toContain('data-vehicle-images="[]"');
});
