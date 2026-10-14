<?php

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;

function odmOwner(): User
{
    if (! Role::find(1)) {
        $role = new Role(['name' => 'Owner']);
        $role->id = 1;
        $role->save();
    }

    return User::factory()->create(['role_id' => 1, 'status' => 'active', 'must_change_password' => false]);
}

function odmCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'ODM Test Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'odm-' . uniqid() . '@example.com',
    ]);
}

function odmTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'ODM Truck ' . fake()->unique()->word(),
        'base_rate' => 1500,
        'per_km_rate' => 60,
    ]);
}

function odmBooking(array $overrides = []): Booking
{
    $customer = odmCustomer();
    $truckType = odmTruckType();

    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'A',
        'dropoff_address' => 'B',
        'distance_km' => 5,
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'computed_total' => 1800,
        'final_total' => 1800,
        'status' => 'completed',
        'created_at' => now(),
        'completed_at' => now(),
    ], $overrides));
}

it('shows the correct completed jobs count excluding cancelled bookings', function () {
    foreach (range(1, 6) as $unused) {
        odmBooking(['status' => 'completed']);
    }
    odmBooking(['status' => 'cancelled']);
    odmBooking(['status' => 'cancelled']);

    $response = $this->actingAs(odmOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSee('₱10,800.00');
    $response->assertSeeInOrder(['Completed Jobs', '6']);
});

it('shows the correct monthly revenue total for completed jobs', function () {
    odmBooking(['status' => 'completed', 'final_total' => 2000]);
    odmBooking(['status' => 'completed', 'final_total' => 4000]);

    $response = $this->actingAs(odmOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSee('₱6,000.00');
});

function odmUnit(array $overrides = []): Unit
{
    return Unit::create(array_merge([
        'name' => 'ODM Unit ' . fake()->unique()->numerify('###'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => odmTruckType()->id,
        'status' => 'available',
    ], $overrides));
}

function odmAudit(string $action, string $entityType = 'Booking', array $overrides = []): AuditLog
{
    $log = AuditLog::create(array_merge([
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => 1,
        'reference' => 'JOB-ODM-0001',
        'description' => 'Dashboard test entry.',
    ], $overrides));

    return $log;
}

it('uses the same completed and paid definition for Revenue Trend and Monthly Revenue', function () {
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0));

    odmBooking(['final_total' => 2500, 'completed_at' => now()->subDays(2)]);
    odmBooking(['final_total' => 1500, 'completed_at' => now()->subDays(10)]);   // this month, outside the 7 days
    odmBooking(['final_total' => 9999, 'completed_at' => null]);                // status override without payment

    $response = $this->actingAs(odmOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSeeInOrder(['Monthly Revenue', '₱4,000.00'], false);
    $response->assertSeeInOrder(['7-day total', '₱2,500.00'], false);
    $response->assertDontSee('₱9,999', false);
    $response->assertSee('Oct 18');
    $response->assertSeeInOrder(['₱3k', '₱2k', '₱1k', '₱0'], false);
});

it('labels the seven days ending today and marks the highest day', function () {
    $this->travelTo(now()->setDate(2026, 10, 11)->setTime(9, 0));

    odmBooking(['final_total' => 12400, 'completed_at' => now()->setDate(2026, 10, 9)->setTime(10, 0)]);
    odmBooking(['final_total' => 6000, 'completed_at' => now()->setDate(2026, 10, 5)->setTime(10, 0)]);

    $response = $this->actingAs(odmOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSeeInOrder(['Oct 5', 'Oct 6', 'Oct 7', 'Oct 8', 'Oct 9', 'Oct 10', 'Oct 11']);
    $response->assertSee('<span class="od-chart-peak">₱12,400</span>', false);
    $response->assertSeeInOrder(['₱15k', '₱10k', '₱5k', '₱0'], false);
});

it('shows available and total units from the dispatch availability evaluation', function () {
    odmUnit();
    odmUnit(['status' => 'maintenance']);

    $response = $this->actingAs(odmOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    // Neither unit has a team leader and driver, so none is ready for dispatch.
    $response->assertSeeInOrder(['Available Units', '0', '/ 2', 'Ready for dispatch / all units'], false);
    $response->assertDontSee('No units registered');
});

it('shows the zero-data states', function () {
    $response = $this->actingAs(odmOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSee('No units registered');
    $response->assertSee('No revenue in the last 7 days');
    $response->assertSee("Revenue is recorded when a completed job's payment is confirmed.", false);
    $response->assertSee('Nothing waiting');
    $response->assertSee('No recent activity');
    $response->assertSee('Booking and quotation events will appear here.');
    $response->assertDontSee('class="od-chart"', false);
    $response->assertDontSee('href="' . route('superadmin.reports.activity') . '" class="od-link-btn"', false);
});

it('counts bookings that need action and upcoming bookings by their existing statuses', function () {
    odmBooking(['status' => 'requested', 'completed_at' => null]);
    odmBooking(['status' => 'requested', 'completed_at' => null]);
    odmBooking(['status' => 'payment_submitted', 'completed_at' => null]);
    odmBooking(['status' => 'scheduled', 'completed_at' => null]);

    $response = $this->actingAs(odmOwner())->get(route('superadmin.dashboard'));
    $html = $response->getContent();

    $response->assertOk();
    $response->assertDontSee('Nothing waiting');
    expect(preg_match('/Pending Bookings.*?<span class="od-count is-attention">2<\/span>/s', $html))->toBe(1);
    expect(preg_match('/Awaiting Verification.*?<span class="od-count is-attention">1<\/span>/s', $html))->toBe(1);
    expect(preg_match('/Returned Jobs.*?<span class="od-count ">0<\/span>/s', $html))->toBe(1);
    expect(preg_match('/Scheduled Bookings.*?<span class="od-count ">1<\/span>/s', $html))->toBe(1);
});

it('uses owner wording only for verified audit actions and groups activity by day', function () {
    $this->travelTo(now()->setDate(2026, 10, 11)->setTime(15, 0));

    odmAudit('payment_confirmed', 'Booking', ['reference' => 'JOB-1039']);
    odmAudit('booking_status_override', 'Booking', ['reference' => 'JOB-1040']);
    $yesterday = odmAudit('booking_rescheduled', 'Booking', ['reference' => 'JOB-1044']);
    $yesterday->created_at = now()->subDay()->setTime(17, 47);
    $yesterday->save();
    $older = odmAudit('create_quotation', 'Quotation', ['reference' => 'Quotation Q-0045']);
    $older->created_at = now()->subDays(3)->setTime(11, 5);
    $older->save();

    $response = $this->actingAs(odmOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSeeInOrder([
        'Today', 'Payment confirmed', 'JOB-1039',
        'Booking Status Override', 'JOB-1040',
        'Yesterday', 'Booking rescheduled', 'JOB-1044', '5:47 PM',
        'Oct 8, 2026', 'Quotation created', 'Q-0045', '11:05 AM',
    ]);
    $response->assertSee('href="' . route('superadmin.reports.activity') . '" class="od-link-btn"', false);
});

it('links to Operations Monitor and drops the old quick-access shortcuts', function () {
    $response = $this->actingAs(odmOwner())->get(route('superadmin.dashboard'));

    $response->assertOk();
    $response->assertSee('href="' . route('superadmin.monitoring.index') . '" class="od-link-btn"', false);
    $response->assertDontSee('Quick Access');
    $response->assertDontSee('Manage Units');
    $response->assertDontSee('cdn.jsdelivr.net/npm/chart.js', false);
});
