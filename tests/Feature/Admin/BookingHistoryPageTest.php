<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * towmate_jarz_testing already carries the same baseline roles (id 1-4) the
 * real seed migration inserts via insertOrIgnore — using the same technique
 * here avoids colliding with that persistent row on the unique `name`
 * column. Only the numeric role_id matters for RoleMiddleware.
 */
function bhRoles(): void
{
    DB::table('roles')->insertOrIgnore([
        ['id' => 1, 'name' => 'Owner', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'Admin', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 3, 'name' => 'Team Leader', 'created_at' => now(), 'updated_at' => now()],
    ]);
}

function bhDispatcher(): User
{
    return User::factory()->create(['role_id' => 2]);
}

function bhTruckType(string $class = 'medium'): TruckType
{
    return TruckType::create([
        'name' => ucfirst($class) . ' Truck ' . uniqid(),
        'class' => $class,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'max_tonnage' => 5,
        'description' => 'Booking History test truck',
    ]);
}

function bhCustomer(string $name): Customer
{
    return Customer::create([
        'full_name' => $name,
        'age' => 30,
        'phone' => '09171234567',
        'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
    ]);
}

function bhTeamLeader(string $name): User
{
    return User::factory()->create(['role_id' => 3, 'name' => $name]);
}

function bhBooking(string $status, array $overrides = []): Booking
{
    $truckType = $overrides['truck_type'] ?? bhTruckType();
    $customer = $overrides['customer'] ?? bhCustomer('BH Customer ' . uniqid());
    $teamLeader = $overrides['team_leader'] ?? null;

    $unit = null;
    if (! array_key_exists('no_unit', $overrides)) {
        $unit = Unit::create([
            'name' => $overrides['unit_name'] ?? 'BH Unit ' . uniqid(),
            'plate_number' => 'BH-' . rand(1000, 9999),
            'truck_type_id' => $truckType->id,
            'team_leader_id' => $teamLeader?->id,
            'status' => 'available',
        ]);
    }

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit?->id,
        'assigned_team_leader_id' => $teamLeader?->id,
        'age' => 30,
        'pickup_address' => 'Pickup Address',
        'dropoff_address' => 'Dropoff Address',
        'distance_km' => 6,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1950,
        'final_total' => $overrides['final_total'] ?? 1950,
        'status' => $status,
        'payment_method' => $overrides['payment_method'] ?? null,
        'cash_received' => $overrides['cash_received'] ?? null,
        'rejection_reason' => $overrides['rejection_reason'] ?? null,
        'completed_at' => $status === 'completed' ? ($overrides['completed_at'] ?? now()) : null,
    ]);

    if (isset($overrides['updated_at'])) {
        // updated_at is intentionally not mass-assignable, so a plain
        // update() silently drops it — forceFill() is required here.
        $booking->timestamps = false;
        $booking->forceFill(['updated_at' => $overrides['updated_at']])->save();
        $booking->timestamps = true;
    }

    return $booking;
}

it('renders the Booking History page', function () {
    bhRoles();
    $dispatcher = bhDispatcher();

    $this->actingAs($dispatcher)
        ->get(route('admin.booking-history'))
        ->assertOk();
});

it('only shows terminal completed/cancelled-like bookings, not active ones', function () {
    bhRoles();
    $dispatcher = bhDispatcher();

    $completed = bhBooking('completed');
    $cancelled = bhBooking('cancelled');
    $active = bhBooking('assigned');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.booking-history'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain($completed->job_code)
        ->and($html)->toContain($cancelled->job_code)
        ->and($html)->not->toContain($active->job_code);
});

it('renders the All/Completed/Cancelled tabs with accurate full-dataset counts, not just the current page', function () {
    bhRoles();
    $dispatcher = bhDispatcher();

    // 11 completed (> the 10/page limit) + 2 cancelled = 13 total.
    for ($i = 0; $i < 11; $i++) {
        bhBooking('completed');
    }
    bhBooking('cancelled');
    bhBooking('rejected');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.booking-history'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-status="all"')
        ->and($html)->toContain('data-status="completed"')
        ->and($html)->toContain('data-status="cancelled"');

    expect($html)->toMatch('/id="bhCountAll">13</');
    expect($html)->toMatch('/id="bhCountCompleted">11</');
    expect($html)->toMatch('/id="bhCountCancelled">2</');

    // Only 10 rows are actually rendered on page 1, but the counts above
    // still reflect the full 13 — proving they are not derived from the DOM.
    expect(substr_count($html, 'jobs-booking-code'))->toBe(10);
});

it('removes the old dark table header and boxed/badge status treatment', function () {
    bhRoles();
    $dispatcher = bhDispatcher();
    bhBooking('completed');
    bhBooking('cancelled');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.booking-history'))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('bh-badge');
    expect($html)->not->toContain('class="bh-tab"');
    expect($html)->toContain('class="jobs-table"');
    expect($html)->toContain('jobs-status-text');
});

it('combines booking code and customer into one column, without showing phone', function () {
    bhRoles();
    $dispatcher = bhDispatcher();
    $customer = bhCustomer('Paolo Reyes');
    $booking = bhBooking('completed', ['customer' => $customer]);

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.booking-history'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('jobs-booking-code">' . $booking->job_code)
        ->and($html)->toContain('Paolo Reyes')
        ->and($html)->not->toContain($customer->phone);
});

it('shows a real cancellation reason when available', function () {
    bhRoles();
    $dispatcher = bhDispatcher();
    $booking = bhBooking('cancelled', ['rejection_reason' => 'Customer requested cancellation']);

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.booking-history'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Customer requested cancellation');
});

it('shows Unit as primary and Team Leader as secondary, or a dash when unassigned', function () {
    bhRoles();
    $dispatcher = bhDispatcher();
    $teamLeader = bhTeamLeader('Marco Reyes');
    $assigned = bhBooking('completed', ['unit_name' => 'Unit 11 · Waling-Waling', 'team_leader' => $teamLeader]);
    $unassigned = bhBooking('cancelled', ['no_unit' => true]);

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.booking-history'))
        ->assertOk()
        ->getContent();

    $posAssigned = strpos($html, $assigned->job_code);
    $rowAssigned = substr($html, $posAssigned, 800);
    expect($rowAssigned)->toContain('Unit 11')
        ->and($rowAssigned)->toContain('Marco Reyes');

    $posUnassigned = strpos($html, $unassigned->job_code);
    $rowUnassigned = substr($html, $posUnassigned, 800);
    expect($rowUnassigned)->toContain('—');
});

it('renders Truck Type from the existing truckType relationship without introducing Truck Class', function () {
    bhRoles();
    $dispatcher = bhDispatcher();
    $lightTruck = bhTruckType('light');
    $booking = bhBooking('completed', ['truck_type' => $lightTruck]);

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.booking-history'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('<th>Truck Type</th>')
        ->and($html)->toContain('Light Duty')
        ->and($html)->not->toContain('Truck Class');

    expect($booking->truckType->class)->toBe('light');
});

it('uses the existing final_total as Amount without recalculating pricing', function () {
    bhRoles();
    $dispatcher = bhDispatcher();
    $booking = bhBooking('completed', ['final_total' => 5656.00]);

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.booking-history'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('₱5,656.00');
    expect($booking->fresh()->final_total)->toEqual(5656.00);
});

it('shows real payment method and Paid only for completed bookings, dash otherwise', function () {
    bhRoles();
    $dispatcher = bhDispatcher();
    $paid = bhBooking('completed', ['payment_method' => 'gcash']);
    $cancelled = bhBooking('cancelled');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.booking-history'))
        ->assertOk()
        ->getContent();

    $posPaid = strpos($html, $paid->job_code);
    $rowPaid = substr($html, $posPaid, 900);
    expect($rowPaid)->toContain('GCash')
        ->and($rowPaid)->toContain('Paid');

    $posCancelled = strpos($html, $cancelled->job_code);
    $rowCancelled = substr($html, $posCancelled, 900);
    expect($rowCancelled)->toContain('—');
});

it('renders date and time from the correct existing timestamp', function () {
    bhRoles();
    $dispatcher = bhDispatcher();
    $completedAt = now()->subDays(2);
    $booking = bhBooking('completed', ['completed_at' => $completedAt]);

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.booking-history'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain($completedAt->format('M d, Y'))
        ->and($html)->toContain($completedAt->format('h:i A'));
});

it('search filters by booking code and by customer name', function () {
    bhRoles();
    $dispatcher = bhDispatcher();
    $target = bhBooking('completed', ['customer' => bhCustomer('Unique Search Target')]);
    bhBooking('completed', ['customer' => bhCustomer('Someone Else')]);

    $byCode = $this->actingAs($dispatcher)
        ->get(route('admin.booking-history', ['search' => $target->booking_code]))
        ->assertOk()
        ->getContent();
    expect(substr_count($byCode, 'jobs-booking-code'))->toBe(1)
        ->and($byCode)->toContain($target->job_code);

    $byCustomer = $this->actingAs($dispatcher)
        ->get(route('admin.booking-history', ['search' => 'Unique Search Target']))
        ->assertOk()
        ->getContent();
    expect(substr_count($byCustomer, 'jobs-booking-code'))->toBe(1)
        ->and($byCustomer)->toContain('Unique Search Target');
});

it('date range filter includes only bookings within the chosen range, inclusively', function () {
    bhRoles();
    $dispatcher = bhDispatcher();

    $inRange = bhBooking('completed', ['updated_at' => '2026-06-15 10:00:00']);
    $outOfRange = bhBooking('completed', ['updated_at' => '2026-01-01 10:00:00']);

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.booking-history', ['date_from' => '2026-06-01', 'date_to' => '2026-06-30']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain($inRange->job_code)
        ->and($html)->not->toContain($outOfRange->job_code);
});

it('paginates exactly 10 rows per page and page 2 returns the remaining records with no duplicates', function () {
    bhRoles();
    $dispatcher = bhDispatcher();

    $bookings = [];
    for ($i = 0; $i < 12; $i++) {
        $bookings[] = bhBooking('completed', ['updated_at' => now()->subMinutes($i)]);
    }

    $page1 = $this->actingAs($dispatcher)
        ->get(route('admin.booking-history'))
        ->assertOk()
        ->getContent();
    expect(substr_count($page1, 'jobs-booking-code'))->toBe(10);

    $page2 = $this->actingAs($dispatcher)
        ->get(route('admin.booking-history', ['page' => 2]))
        ->assertOk()
        ->getContent();
    expect(substr_count($page2, 'jobs-booking-code'))->toBe(2);

    // No booking code appears on both pages.
    foreach ($bookings as $booking) {
        $onPage1 = str_contains($page1, $booking->job_code);
        $onPage2 = str_contains($page2, $booking->job_code);
        expect($onPage1 && $onPage2)->toBeFalse();
    }
});

it('preserves the active status filter and search across pagination', function () {
    bhRoles();
    $dispatcher = bhDispatcher();

    for ($i = 0; $i < 12; $i++) {
        bhBooking('completed', ['updated_at' => now()->subMinutes($i)]);
    }
    bhBooking('cancelled');

    // Requested the same way the page's own JS does when paginating — via
    // the AJAX partial, not the full page (the full page's static tab
    // labels always say "Cancelled" regardless of the active filter, which
    // would make a whole-page assertion meaningless here).
    $page2Html = $this->actingAs($dispatcher)
        ->get(route('admin.booking-history', ['status' => 'completed', 'page' => 2]), [
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->assertOk()
        ->getContent();

    // Page 2 of the "completed" filter must still contain only completed
    // rows — proving the status filter carried through pagination.
    expect(substr_count($page2Html, 'jobs-booking-code'))->toBe(2);
    expect($page2Html)->not->toContain('Cancelled');
});

it('shows a plain empty state when no history matches the current filters', function () {
    bhRoles();
    $dispatcher = bhDispatcher();
    bhBooking('completed');

    $html = $this->actingAs($dispatcher)
        ->get(route('admin.booking-history', ['search' => 'no-such-booking-exists']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('No booking history found.');
});

it('does not mutate any booking status when rendering or filtering history', function () {
    bhRoles();
    $dispatcher = bhDispatcher();
    $completed = bhBooking('completed');
    $cancelled = bhBooking('cancelled');

    $this->actingAs($dispatcher)->get(route('admin.booking-history'))->assertOk();
    $this->actingAs($dispatcher)->get(route('admin.booking-history', ['status' => 'cancelled']))->assertOk();
    $this->actingAs($dispatcher)->get(route('admin.booking-history', ['search' => 'x']))->assertOk();

    expect($completed->fresh()->status)->toBe('completed');
    expect($cancelled->fresh()->status)->toBe('cancelled');
});
