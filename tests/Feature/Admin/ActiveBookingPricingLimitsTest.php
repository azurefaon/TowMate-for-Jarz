<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TruckType;
use App\Models\User;

function abplRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function abplDispatcher(): User
{
    abplRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
}

function abplTeamLeader(): User
{
    abplRole(3, 'Team Leader');

    return User::factory()->create(['role_id' => 3, 'status' => 'active', 'must_change_password' => false]);
}

function abplBooking(string $status = 'accepted'): Booking
{
    $truckType = TruckType::create([
        'name' => 'ABPL Truck ' . fake()->unique()->word(),
        'base_rate' => 2500,
        'per_km_rate' => 120,
    ]);

    $user = User::factory()->create(['role_id' => abplRole(5, 'Customer')->id]);
    $customer = Customer::create([
        'user_id' => $user->id,
        'full_name' => $user->name,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => $user->email,
    ]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pickup Point',
        'dropoff_address' => 'Dropoff Point',
        'distance_km' => 15,
        'base_rate' => 2500,
        'per_km_rate' => 120,
        'computed_total' => 3820,
        'discount_percentage' => 0,
        'additional_fee' => 0,
        'vat_exclusive_total' => 3820,
        'vat_amount' => 458.4,
        'final_total' => 4278.4,
        'status' => $status,
        'service_type' => 'book_now',
    ]);
    $booking->update(['booking_code' => 'TM-ABPL' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);

    return $booking->fresh();
}

it('1: dispatcher can update pricing within Owner-configured limits', function () {
    SystemSetting::setValue('max_dispatcher_discount_percentage', 10);
    SystemSetting::setValue('max_additional_charge', 1000);
    $booking = abplBooking();

    $response = test()->actingAs(abplDispatcher())->patchJson(
        route('admin.active-bookings.update-pricing', $booking),
        ['additional_fee' => 200, 'discount_percentage' => 5]
    );

    $response->assertOk()->assertJsonPath('success', true);

    $booking->refresh();
    expect((float) $booking->additional_fee)->toBe(200.0)
        ->and((float) $booking->discount_percentage)->toBe(5.0);
});

it('2: a discount above the configured limit is rejected', function () {
    SystemSetting::setValue('max_dispatcher_discount_percentage', 10);
    $booking = abplBooking();

    $response = test()->actingAs(abplDispatcher())->patchJson(
        route('admin.active-bookings.update-pricing', $booking),
        ['discount_percentage' => 15]
    );

    $response->assertStatus(422);
    expect((float) $booking->fresh()->discount_percentage)->toBe(0.0);
});

it('3: an additional charge above the configured limit is rejected', function () {
    SystemSetting::setValue('max_additional_charge', 500);
    $booking = abplBooking();

    $response = test()->actingAs(abplDispatcher())->patchJson(
        route('admin.active-bookings.update-pricing', $booking),
        ['additional_fee' => 600]
    );

    $response->assertStatus(422);
    expect((float) $booking->fresh()->additional_fee)->toBe(0.0);
});

it('4: a boundary value exactly at the configured limit succeeds', function () {
    SystemSetting::setValue('max_dispatcher_discount_percentage', 10);
    SystemSetting::setValue('max_additional_charge', 500);
    $booking = abplBooking();

    $response = test()->actingAs(abplDispatcher())->patchJson(
        route('admin.active-bookings.update-pricing', $booking),
        ['additional_fee' => 500, 'discount_percentage' => 10]
    );

    $response->assertOk();
    $booking->refresh();
    expect((float) $booking->additional_fee)->toBe(500.0)
        ->and((float) $booking->discount_percentage)->toBe(10.0);
});

it('5: a completed booking pricing cannot be changed', function () {
    $booking = abplBooking('completed');

    $response = test()->actingAs(abplDispatcher())->patchJson(
        route('admin.active-bookings.update-pricing', $booking),
        ['additional_fee' => 200]
    );

    $response->assertStatus(422);
    expect((float) $booking->fresh()->additional_fee)->toBe(0.0);
});

it('6: cancelled and rejected terminal bookings cannot have pricing changed', function () {
    foreach (['cancelled', 'rejected'] as $status) {
        $booking = abplBooking($status);

        $response = test()->actingAs(abplDispatcher())->patchJson(
            route('admin.active-bookings.update-pricing', $booking),
            ['additional_fee' => 200]
        );

        $response->assertStatus(422);
        expect((float) $booking->fresh()->additional_fee)->toBe(0.0);
    }
});

it('7: a rejected pricing update leaves the original database values unchanged', function () {
    SystemSetting::setValue('max_additional_charge', 100);
    $booking = abplBooking();
    $originalFinalTotal = (float) $booking->final_total;
    $originalVatAmount = (float) $booking->vat_amount;

    test()->actingAs(abplDispatcher())->patchJson(
        route('admin.active-bookings.update-pricing', $booking),
        ['additional_fee' => 999]
    )->assertStatus(422);

    $booking->refresh();
    expect((float) $booking->final_total)->toBe($originalFinalTotal)
        ->and((float) $booking->vat_amount)->toBe($originalVatAmount)
        ->and((float) $booking->additional_fee)->toBe(0.0);
});

it('8: the stored final total is recomputed consistently server-side', function () {
    $booking = abplBooking();

    test()->actingAs(abplDispatcher())->patchJson(
        route('admin.active-bookings.update-pricing', $booking),
        ['additional_fee' => 300, 'discount_percentage' => 10]
    )->assertOk();

    $booking->refresh();
    // gross 3820, 10% discount -> subtotal 3438, VAT 12% -> 412.56, base 3850.56, +300 -> 4150.56
    expect((float) $booking->vat_exclusive_total)->toBe(3438.0)
        ->and((float) $booking->vat_amount)->toBe(412.56)
        ->and((float) $booking->final_total)->toBe(4150.56);
});

it('9: an unauthorized role cannot use the Dispatcher pricing endpoint', function () {
    $booking = abplBooking();

    $response = test()->actingAs(abplTeamLeader())->patchJson(
        route('admin.active-bookings.update-pricing', $booking),
        ['additional_fee' => 200]
    );

    $response->assertStatus(403);
    expect((float) $booking->fresh()->additional_fee)->toBe(0.0);
});

it('10: the existing allowed pre-completion pricing flow still works for every active status', function () {
    foreach (['accepted', 'assigned', 'confirmed', 'on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff'] as $status) {
        $booking = abplBooking($status);

        $response = test()->actingAs(abplDispatcher())->patchJson(
            route('admin.active-bookings.update-pricing', $booking),
            ['additional_fee' => 100]
        );

        $response->assertOk();
        expect((float) $booking->fresh()->additional_fee)->toBe(100.0);
    }
});

it('11: a booking waiting for payment verification has its pricing locked', function () {
    $booking = abplBooking('waiting_verification');

    $response = test()->actingAs(abplDispatcher())->patchJson(
        route('admin.active-bookings.update-pricing', $booking),
        ['additional_fee' => 200]
    );

    $response->assertStatus(422);
    expect((float) $booking->fresh()->additional_fee)->toBe(0.0);
});
