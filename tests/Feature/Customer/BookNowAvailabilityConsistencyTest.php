<?php

use App\Models\Customer;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Support\Facades\DB;

function bnRoles(): void
{
    DB::table('roles')->insertOrIgnore([
        ['id' => 3, 'name' => 'Team Leader', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 5, 'name' => 'Customer', 'created_at' => now(), 'updated_at' => now()],
    ]);
}

function bnTeamLeader(): User
{
    return User::factory()->create(['role_id' => 3, 'must_change_password' => false]);
}

function bnTruckType(string $class = 'light'): TruckType
{
    return TruckType::create([
        'name' => ucfirst($class) . ' Truck ' . uniqid(),
        'class' => $class,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'max_tonnage' => 5,
        'description' => 'BN test truck',
        'status' => 'active',
    ]);
}

function bnUnit(array $overrides = []): Unit
{
    $truckType = $overrides['truck_type'] ?? bnTruckType();

    return Unit::create(array_merge([
        'name' => 'JARZ BN ' . uniqid(),
        'plate_number' => strtoupper(substr(uniqid(), 0, 3)) . rand(1000, 9999),
        'truck_type_id' => $truckType->id,
        'status' => 'available',
    ], array_diff_key($overrides, ['truck_type' => null])));
}

function bnCustomer(): Customer
{
    $user = User::factory()->create(['role_id' => 5]);

    return Customer::create([
        'user_id' => $user->id,
        'full_name' => 'BN Customer ' . uniqid(),
        'age' => 30,
        'phone' => '09171234567',
        'email' => uniqid() . '@example.test',
    ]);
}

function bnData(Customer $customer, TruckType $truckType, string $serviceType = 'book_now'): array
{
    return [
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pasay City',
        'dropoff_address' => 'Makati City',
        'distance_km' => 5,
        'service_type' => $serviceType,
        'extra_vehicles' => [],
    ];
}

it('A. Matching truck type + fully eligible unit keeps Book Now immediate at quotation creation', function () {
    bnRoles();
    $truckType = bnTruckType('light');
    $tl = bnTeamLeader();
    bnUnit(['truck_type' => $truckType, 'team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz']);
    $customer = bnCustomer();

    $quotation = app(BookingService::class)->createCustomerQuotation(
        bnData($customer, $truckType, 'book_now'),
        null,
        $customer
    );

    expect($quotation->service_type)->toBe('book_now')
        ->and($quotation->scheduled_date)->toBeNull();
});

it('B. No eligible unit for the requested truck type forces the quotation to Scheduled', function () {
    bnRoles();
    $truckType = bnTruckType('light');
    $customer = bnCustomer();

    $quotation = app(BookingService::class)->createCustomerQuotation(
        bnData($customer, $truckType, 'book_now'),
        null,
        $customer
    );

    expect($quotation->service_type)->toBe('schedule')
        ->and($quotation->scheduled_date)->not->toBeNull();
});

it('C. A unit that is available but of the wrong truck type does not count toward eligibility', function () {
    bnRoles();
    $requestedType = bnTruckType('light');
    $otherType = bnTruckType('heavy');
    $tl = bnTeamLeader();
    bnUnit(['truck_type' => $otherType, 'team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz']);
    $customer = bnCustomer();

    $quotation = app(BookingService::class)->createCustomerQuotation(
        bnData($customer, $requestedType, 'book_now'),
        null,
        $customer
    );

    expect($quotation->service_type)->toBe('schedule');
});

it('D. An eligible Book Now quotation is accepted as an immediate (confirmed) booking, not scheduled', function () {
    bnRoles();
    $truckType = bnTruckType('light');
    $tl = bnTeamLeader();
    bnUnit(['truck_type' => $truckType, 'team_leader_id' => $tl->id, 'driver_name' => 'Juan Cruz']);
    $customer = bnCustomer();

    $quotationService = app(\App\Services\QuotationService::class);
    $quotation = app(BookingService::class)->createCustomerQuotation(
        bnData($customer, $truckType, 'book_now'),
        null,
        $customer
    );

    $quotationService->sendQuotation($quotation, 168);
    $booking = $quotationService->acceptQuotation($quotation->fresh());

    expect($booking->service_type)->toBe('book_now')
        ->and($booking->status)->toBe('confirmed');
});

it('E. An ineligible request is accepted as a scheduled (scheduled_confirmed) booking, not confirmed', function () {
    bnRoles();
    $truckType = bnTruckType('light');
    $customer = bnCustomer();

    $quotationService = app(\App\Services\QuotationService::class);
    $quotation = app(BookingService::class)->createCustomerQuotation(
        bnData($customer, $truckType, 'book_now'),
        null,
        $customer
    );

    $quotation->update([
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
    ]);

    $quotationService->sendQuotation($quotation, 168);
    $booking = $quotationService->acceptQuotation($quotation->fresh());

    expect($booking->service_type)->toBe('schedule')
        ->and($booking->status)->toBe('scheduled_confirmed');
});
