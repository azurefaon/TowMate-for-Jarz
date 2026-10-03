<?php

use App\Events\BookingCancelled;
use App\Events\BookingStatusUpdated;
use App\Http\Controllers\Admin\JobsController;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/*
 * Dispatcher operational cancellation of an active booking
 * (POST admin-dashboard/jobs/{booking}/cancel). Operational only: no fee,
 * invoice, receipt or payment behaviour.
 */

function dcRoles(): void
{
    foreach ([1 => 'Owner', 2 => 'Admin', 3 => 'Team Leader', 5 => 'Customer', 6 => 'System Admin'] as $id => $name) {
        DB::table('roles')->insertOrIgnore([
            ['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}

function dcUser(int $roleId): User
{
    dcRoles();

    return User::factory()->create(['role_id' => $roleId, 'must_change_password' => false]);
}

function dcTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'DC Truck ' . uniqid(),
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'max_tonnage' => 5,
        'description' => 'Cancel test truck',
    ]);
}

function dcCustomer(?User $user = null): Customer
{
    return Customer::create([
        'user_id' => $user?->id,
        'full_name' => 'DC Customer ' . uniqid(),
        'age' => 30,
        'phone' => '09171234567',
        'email' => 'dc.' . uniqid() . '@example.test',
    ]);
}

/** @return array{0: Unit, 1: User} */
function dcUnitWithLeader(TruckType $truck): array
{
    $leader = dcUser(3);
    $unit = Unit::create([
        'name' => 'DC Unit ' . uniqid(),
        'plate_number' => 'DC-' . rand(1000, 9999),
        'truck_type_id' => $truck->id,
        'team_leader_id' => $leader->id,
        'status' => 'on_job',
    ]);

    return [$unit, $leader];
}

function dcBooking(string $status, array $o = []): Booking
{
    $truck = $o['truck'] ?? dcTruckType();
    [$unit, $leader] = isset($o['unit']) ? [$o['unit'], $o['leader']] : dcUnitWithLeader($truck);
    $customer = $o['customer'] ?? dcCustomer();

    return Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $leader->id,
        'driver_name' => 'DC Driver',
        'group_code' => $o['group_code'] ?? null,
        'age' => 30,
        'pickup_address' => 'Quezon City Circle',
        'pickup_lat' => 14.6760,
        'pickup_lng' => 121.0437,
        'dropoff_address' => 'SM Megamall, Mandaluyong',
        'dropoff_lat' => 14.5847,
        'dropoff_lng' => 121.0567,
        'distance_km' => 6,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 1950,
        'final_total' => 1950,
        'status' => $status,
        'assigned_at' => now(),
    ]);
}

function dcCancel(Booking $booking, ?string $reason = 'Customer requested cancellation after dispatch.')
{
    return test()->postJson(route('admin.jobs.cancel', $booking), ['reason' => $reason]);
}

// ---------------------------------------------------------------- AUTHORIZATION

it('lets the dispatcher cancel an allowed active booking', function () {
    $booking = dcBooking('on_the_way');

    test()->actingAs(dcUser(2));
    dcCancel($booking)->assertOk()->assertJsonPath('success', true);

    expect($booking->fresh()->status)->toBe('cancelled');
});

it('rejects the customer, team leader, system admin and guests', function () {
    $booking = dcBooking('on_the_way');

    $this->actingAs(dcUser(5));
    dcCancel($booking)->assertForbidden();

    $this->actingAs(dcUser(3));
    dcCancel($booking)->assertForbidden();

    $this->actingAs(dcUser(6));
    dcCancel($booking)->assertForbidden();

    auth()->logout();
    $this->app['auth']->forgetGuards();
    dcCancel($booking)->assertUnauthorized();

    expect($booking->fresh()->status)->toBe('on_the_way');
});

// ----------------------------------------------------------------- VALIDATION

it('requires a non-empty cancellation reason', function (?string $reason) {
    $booking = dcBooking('accepted');

    $this->actingAs(dcUser(2));
    dcCancel($booking, $reason)->assertStatus(422);

    expect($booking->fresh()->status)->toBe('accepted');
})->with([[null], [''], ['   '], ['<b></b>']]);

// ----------------------------------------------------------- ALLOWED / BLOCKED

it('cancels from every approved status', function (string $status) {
    $booking = dcBooking($status);

    $this->actingAs(dcUser(2));
    dcCancel($booking)->assertOk();

    expect($booking->fresh()->status)->toBe('cancelled');
})->with(['assigned', 'accepted', 'on_the_way', 'arrived_pickup', 'in_progress']);

it('blocks every non-approved status and leaves the booking untouched', function (string $status) {
    $booking = dcBooking($status);

    $this->actingAs(dcUser(2));
    dcCancel($booking)->assertStatus(409);

    $fresh = $booking->fresh();
    expect($fresh->status)->toBe($status);
    expect($fresh->assigned_unit_id)->not->toBeNull();
    expect(AuditLog::where('action', 'booking_cancelled_by_dispatcher')->count())->toBe(0);
})->with([
    'loading_vehicle', 'on_job', 'arrived_dropoff', 'waiting_verification',
    'payment_pending', 'payment_submitted', 'completed', 'cancelled', 'rejected', 'returned',
]);

it('rejects a duplicate cancellation cleanly', function () {
    $booking = dcBooking('accepted');

    $this->actingAs(dcUser(2));
    dcCancel($booking)->assertOk();
    dcCancel($booking)->assertStatus(409);

    expect(AuditLog::where('action', 'booking_cancelled_by_dispatcher')->count())->toBe(1);
});

// ---------------------------------------------------------------- SIDE EFFECTS

it('cancels, clears assignment, releases the unit and leaves Active Jobs for Booking History', function () {
    $booking = dcBooking('on_the_way');
    $unitId = $booking->assigned_unit_id;
    $dispatcher = dcUser(2);

    $this->actingAs($dispatcher);
    dcCancel($booking)->assertOk();

    $fresh = $booking->fresh();
    expect($fresh->status)->toBe('cancelled');
    expect($fresh->quotation_status)->toBe('cancelled');
    expect($fresh->rejection_reason)->toBe('Customer requested cancellation after dispatch.');
    expect($fresh->assigned_unit_id)->toBeNull();
    expect($fresh->assigned_team_leader_id)->toBeNull();
    expect($fresh->driver_name)->toBeNull();
    expect(Unit::find($unitId)->status)->toBe('available');

    $this->get(route('admin.jobs'))->assertOk()->assertDontSee($booking->job_code);
    $this->get(route('admin.booking-history'))->assertOk()->assertSee($booking->job_code);
});

it('does not touch money fields, invoices or payment state', function () {
    $booking = dcBooking('arrived_pickup');
    $before = $booking->fresh()->only(['final_total', 'computed_total', 'additional_fee', 'cash_received', 'payment_method', 'payment_submitted_at']);

    $this->actingAs(dcUser(2));
    dcCancel($booking, 'Customer requested cancellation after dispatch. Base rate agreed outside TowMate.')->assertOk();

    expect($booking->fresh()->only(array_keys($before)))->toEqual($before);
});

it('removes the task from the team leader and stops customer tracking', function () {
    $customerUser = dcUser(5);
    $customer = dcCustomer($customerUser);
    $booking = dcBooking('on_the_way', ['customer' => $customer]);
    $leader = User::find($booking->assigned_team_leader_id);

    Sanctum::actingAs($leader, ['*']);
    $this->getJson('/api/v1/team-leader/task')->assertOk()->assertJsonPath('data.booking_code', $booking->booking_code);

    $this->actingAs(dcUser(2));
    dcCancel($booking)->assertOk();

    Sanctum::actingAs($leader, ['*']);
    $this->getJson('/api/v1/team-leader/task')->assertOk()->assertJsonPath('data', null);

    // A stale team leader screen can no longer act on the booking.
    $this->patchJson("/api/v1/team-leader/task/{$booking->booking_code}/status", ['status' => 'arrived_pickup'])
        ->assertStatus(403);
    $this->postJson("/api/v1/team-leader/task/{$booking->booking_code}/return", [
        'reason' => 'Other',
    ])->assertStatus(403);

    Sanctum::actingAs($customerUser, ['*']);
    $this->getJson("/api/v1/bookings/{$booking->booking_code}/tracking")
        ->assertOk()
        ->assertJsonPath('data.tracking', false)
        ->assertJsonPath('data.location', null);
});

it('fires the cancellation and status events', function () {
    Event::fake([BookingCancelled::class, BookingStatusUpdated::class]);
    $booking = dcBooking('accepted');

    $this->actingAs(dcUser(2));
    dcCancel($booking)->assertOk();

    Event::assertDispatched(BookingCancelled::class, fn($e) => $e->booking->id === $booking->id);
    Event::assertDispatched(BookingStatusUpdated::class, fn($e) => $e->booking->id === $booking->id);
});

it('writes a dedicated audit entry with actor, reason, previous status and resources', function () {
    $booking = dcBooking('in_progress', ['group_code' => 'GRP-DC-1']);
    $leaderId = $booking->assigned_team_leader_id;
    $unitId = $booking->assigned_unit_id;
    $dispatcher = dcUser(2);

    $this->actingAs($dispatcher);
    dcCancel($booking, 'Customer changed their mind.')->assertOk();

    $log = AuditLog::where('action', 'booking_cancelled_by_dispatcher')->firstOrFail();
    expect($log->user_id)->toBe($dispatcher->id);
    expect($log->entity_id)->toBe($booking->id);
    expect($log->old_value['previous_status'])->toBe('in_progress');
    expect($log->old_value['team_leader_id'])->toBe($leaderId);
    expect($log->old_value['unit_id'])->toBe($unitId);
    expect($log->old_value['driver_name'])->toBe('DC Driver');
    expect($log->new_value['reason'])->toBe('Customer changed their mind.');
    expect($log->new_value['actor_role_id'])->toBe(2);
    expect($log->new_value['group_code'])->toBe('GRP-DC-1');
    expect($log->new_value['unit_released'])->toBeTrue();
    expect($log->new_value)->toHaveKey('cancelled_at');
});

// ---------------------------------------------------------------------- GROUPS

it('cancels only the selected booking and keeps a shared unit for an active sibling', function () {
    $truck = dcTruckType();
    [$unit, $leader] = dcUnitWithLeader($truck);
    $customer = dcCustomer();
    $shared = ['truck' => $truck, 'unit' => $unit, 'leader' => $leader, 'customer' => $customer, 'group_code' => 'GRP-DC-2'];

    $target = dcBooking('on_the_way', $shared);
    $sibling = dcBooking('on_the_way', $shared);

    $this->actingAs(dcUser(2));
    dcCancel($target)->assertOk();

    expect($target->fresh()->status)->toBe('cancelled');

    $sib = $sibling->fresh();
    expect($sib->status)->toBe('on_the_way');
    expect($sib->assigned_unit_id)->toBe($unit->id);
    expect($sib->assigned_team_leader_id)->toBe($leader->id);
    expect($sib->quotation_status)->not->toBe('cancelled');
    expect($unit->fresh()->status)->toBe('on_job');

    $log = AuditLog::where('action', 'booking_cancelled_by_dispatcher')->firstOrFail();
    expect($log->new_value['unit_released'])->toBeFalse();
    expect($log->new_value['team_leader_released'])->toBeFalse();
});

it('releases the unit when the only other group member uses a different unit', function () {
    $truck = dcTruckType();
    $target = dcBooking('on_the_way', ['truck' => $truck, 'group_code' => 'GRP-DC-3']);
    $sibling = dcBooking('on_the_way', ['truck' => $truck, 'group_code' => 'GRP-DC-3']);
    $targetUnitId = $target->assigned_unit_id;

    $this->actingAs(dcUser(2));
    dcCancel($target)->assertOk();

    expect(Unit::find($targetUnitId)->status)->toBe('available');
    expect($sibling->fresh()->status)->toBe('on_the_way');
    expect(Unit::find($sibling->assigned_unit_id)->status)->toBe('on_job');
});

// ------------------------------------------------------------------------ RACE

it('re-checks the status after the lock so a stale page cannot cancel a progressed booking', function () {
    $booking = dcBooking('arrived_pickup');
    $stale = Booking::find($booking->id); // page loaded while still cancellable

    // The team leader progresses before the dispatcher's request reaches the server.
    Booking::whereKey($booking->id)->update(['status' => 'on_job']);

    $dispatcher = dcUser(2);
    $request = Request::create('/cancel', 'POST', ['reason' => 'Stale page cancel']);
    $request->setUserResolver(fn() => $dispatcher);

    $response = app(JobsController::class)->cancel($request, $stale);

    expect($response->getStatusCode())->toBe(409);
    expect(Booking::find($booking->id)->status)->toBe('on_job');
    expect(Booking::find($booking->id)->assigned_unit_id)->not->toBeNull();
    expect(AuditLog::where('action', 'booking_cancelled_by_dispatcher')->count())->toBe(0);
});

// ------------------------------------------------------------------- UI WIRING

it('renders the cancel action and modal on Active Jobs with the cancel url', function () {
    $booking = dcBooking('on_the_way');

    $this->actingAs(dcUser(2))
        ->get(route('admin.jobs'))
        ->assertOk()
        ->assertSee('job-detail-cancel-btn', false)
        ->assertSee('Cancel this active booking?')
        ->assertSee('Confirm cancellation')
        ->assertSee(route('admin.jobs.cancel', $booking), false);
});
