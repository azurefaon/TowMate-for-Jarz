<?php

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;

function craRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function craDispatcher(): User
{
    craRole(2, 'Dispatcher');

    return User::factory()->create(['role_id' => 2, 'status' => 'active', 'must_change_password' => false]);
}

function craOwner(): User
{
    craRole(1, 'Owner');

    return User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
}

function craSystemAdmin(): User
{
    craRole(6, 'System Admin');

    return User::factory()->create(['role_id' => 6, 'status' => 'active', 'must_change_password' => false]);
}

function craBookingWithCustomer(?string $initialRiskLevel = null): Booking
{
    $user = User::factory()->create(['role_id' => craRole(5, 'Customer')->id]);
    $customer = Customer::create([
        'user_id'   => $user->id,
        'full_name' => 'Juan Dela Cruz',
        'phone'     => '0917' . fake()->unique()->numerify('#######'),
        'email'     => $user->email,
        'risk_level' => $initialRiskLevel,
    ]);
    $truckType = TruckType::create(['name' => 'CRA Truck ' . fake()->unique()->word(), 'base_rate' => 1500, 'per_km_rate' => 60]);
    $booking = Booking::create([
        'customer_id'   => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A', 'dropoff_address' => 'B', 'distance_km' => 5,
        'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => 1800, 'final_total' => 2016,
        'status' => 'requested',
    ]);
    $booking->update(['booking_code' => 'TM-CRA' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);

    return $booking->fresh();
}

it('still updates the customer risk fields and returns the existing json response', function () {
    $booking = craBookingWithCustomer();

    $response = $this->actingAs(craDispatcher())->post(route('admin.booking.mark-risk', $booking), [
        'risk_level' => 'high',
        'risk_reason' => 'Repeated no-shows.',
    ]);

    $response->assertOk();
    $response->assertJson(['success' => true, 'risk_level' => 'high']);

    $customer = $booking->customer->fresh();
    expect($customer->risk_level)->toBe('high');
    expect($customer->risk_reason)->toBe('Repeated no-shows.');
});

it('creates a real audit log event when customer risk changes', function () {
    $booking = craBookingWithCustomer();

    $this->actingAs(craDispatcher())->post(route('admin.booking.mark-risk', $booking), [
        'risk_level' => 'medium',
        'risk_reason' => 'Late cancellations.',
    ])->assertOk();

    $log = AuditLog::where('action', 'customer_risk_updated')->latest()->first();

    expect($log)->not->toBeNull();
    expect($log->entity_type)->toBe('Customer');
    expect($log->entity_id)->toBe($booking->customer->id);
    expect($log->reference)->toBe('Juan Dela Cruz');
});

it('classifies the customer risk event as a business action', function () {
    expect(\App\Services\AuditLogService::isBusinessAction('customer_risk_updated'))->toBeTrue();
});

it('shows the customer risk event in owner business activity', function () {
    $booking = craBookingWithCustomer();

    $this->actingAs(craDispatcher())->post(route('admin.booking.mark-risk', $booking), [
        'risk_level' => 'blacklist',
        'risk_reason' => 'Fraudulent payment attempt.',
    ])->assertOk();

    $response = $this->actingAs(craOwner())->get(route('superadmin.reports.activity'));

    $response->assertOk();
    $response->assertSee('Customer Risk Updated');
    $response->assertSee('Juan Dela Cruz');
});

it('excludes the customer risk event from system admin audit logs', function () {
    $booking = craBookingWithCustomer();

    $this->actingAs(craDispatcher())->post(route('admin.booking.mark-risk', $booking), [
        'risk_level' => 'high',
        'risk_reason' => 'CRA_HIDDEN_FROM_SYSTEM_ADMIN reason text.',
    ])->assertOk();

    $response = $this->actingAs(craSystemAdmin())->get(route('system-admin.audit-logs.index'));

    $response->assertOk();
    $response->assertDontSee('CRA_HIDDEN_FROM_SYSTEM_ADMIN');
});

it('renders the old and new risk values as a readable diff, not raw field names', function () {
    $booking = craBookingWithCustomer();

    $this->actingAs(craDispatcher())->post(route('admin.booking.mark-risk', $booking), [
        'risk_level' => 'medium',
        'risk_reason' => 'Repeated late cancellations.',
    ])->assertOk();

    $response = $this->actingAs(craOwner())->get(route('superadmin.reports.activity'));

    $response->assertOk();
    $response->assertSee('Normal');
    $response->assertSee('Watchlist');
    $response->assertDontSee('risk_level', false);
    $response->assertDontSee('"risk"', false);
});

it('renders blacklisted risk transitions correctly', function () {
    $booking = craBookingWithCustomer('medium');

    $this->actingAs(craDispatcher())->post(route('admin.booking.mark-risk', $booking), [
        'risk_level' => 'blacklist',
        'risk_reason' => 'Confirmed fraud.',
    ])->assertOk();

    $response = $this->actingAs(craOwner())->get(route('superadmin.reports.activity'));

    $response->assertOk();
    $response->assertSee('Watchlist');
    $response->assertSee('Blacklisted');
});

it('shows the reason as a plain readable line, not an old/none diff', function () {
    $booking = craBookingWithCustomer();

    $this->actingAs(craDispatcher())->post(route('admin.booking.mark-risk', $booking), [
        'risk_level' => 'high',
        'risk_reason' => 'CRA_REASON_TEXT_VISIBLE',
    ])->assertOk();

    $response = $this->actingAs(craOwner())->get(route('superadmin.reports.activity'));
    $content = $response->getContent();

    $response->assertOk();
    $response->assertSee('Reason');
    $response->assertSee('CRA_REASON_TEXT_VISIBLE');
    expect($content)->not->toContain('(none) → CRA_REASON_TEXT_VISIBLE');
});

it('does not log unrelated customer fields such as email or phone', function () {
    $booking = craBookingWithCustomer();

    $this->actingAs(craDispatcher())->post(route('admin.booking.mark-risk', $booking), [
        'risk_level' => 'high',
        'risk_reason' => 'Reason text.',
    ])->assertOk();

    $log = AuditLog::where('action', 'customer_risk_updated')->latest()->first();

    expect($log->old_value)->not->toHaveKey('email');
    expect($log->old_value)->not->toHaveKey('phone');
    expect($log->new_value)->not->toHaveKey('email');
    expect($log->new_value)->not->toHaveKey('phone');
});
