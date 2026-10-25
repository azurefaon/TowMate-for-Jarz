<?php

// Phase B: dispatcher "Queue Next Task" + Team Leader accept/decline + manual promotion.

use App\Events\BookingStatusUpdated;
use App\Exceptions\QueueOfferException;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingQueueOffer;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Services\NextTaskQueueService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => config(['towmate.next_task_queue_enabled' => true, 'towmate.next_task_queue_team_leaders' => '*']));

function ntqRole(int $id, string $name): void
{
    if (! Role::find($id)) {
        $role = new Role(['name' => $name]);
        $role->id = $id;
        $role->save();
    }
}

function ntqDispatcher(): User
{
    ntqRole(1, 'Owner');
    ntqRole(2, 'Dispatcher');
    ntqRole(3, 'Team Leader');
    ntqRole(5, 'Customer');

    return User::factory()->create(['role_id' => 2, 'must_change_password' => false]);
}

function ntqTruck(string $name = 'NTQ Light'): TruckType
{
    return TruckType::firstOrCreate(['name' => $name], [
        'class' => 'light', 'base_rate' => 1500, 'per_km_rate' => 300, 'status' => 'active',
    ]);
}

function ntqUnit(TruckType $truck, string $name): Unit
{
    ntqDispatcher();
    $leader = User::factory()->create(['role_id' => 3, 'must_change_password' => false, 'last_ping_at' => now()]);
    Cache::put("teamleader:presence:{$leader->id}", now()->timestamp, now()->addMinutes(2));

    return Unit::create([
        'name' => $name,
        'plate_number' => strtoupper(substr(md5($name . microtime()), 0, 7)),
        'truck_type_id' => $truck->id,
        'team_leader_id' => $leader->id,
        'status' => 'available',
        'driver_name' => 'NTQ DRIVER',
        'current_lat' => 14.62,
        'current_lng' => 121.04,
        'location_updated_at' => now()->subMinutes(30), // stale on purpose: must not block
    ]);
}

function ntqBooking(TruckType $truck, string $status = 'confirmed', array $extra = []): Booking
{
    $customer = Customer::firstOrCreate(['email' => 'ntq@example.com'], ['full_name' => 'Queue Customer', 'phone' => '09170000000']);

    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truck->id,
        'pickup_address' => 'Queue Pickup',
        'pickup_lat' => 14.6760,
        'pickup_lng' => 121.0437,
        'dropoff_address' => 'Queue Dropoff',
        'dropoff_lat' => 14.5547,
        'dropoff_lng' => 121.0244,
        'distance_km' => 12.5,
        'base_rate' => 1500,
        'per_km_rate' => 300,
        'computed_total' => 4050,
        'final_total' => 4536,
        'vat_exclusive_total' => 4050,
        'status' => $status,
        'service_type' => 'book_now',
    ], $extra));
}

/** A unit whose Team Leader is finishing a job at the drop-off. @return array{0: Unit, 1: Booking} */
function ntqFinishing(TruckType $truck, string $name = 'Finishing 01', string $status = 'arrived_dropoff'): array
{
    $unit = ntqUnit($truck, $name);
    $job = ntqBooking($truck, $status, [
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $unit->team_leader_id,
        'assigned_at' => now(),
    ]);

    return [$unit, $job];
}

function ntqOffer($test, User $dispatcher, Booking $booking, Unit $unit)
{
    return $test->actingAs($dispatcher)->postJson(route('admin.booking.queue-offer.store', $booking->booking_code), ['unit_id' => $unit->id]);
}

/** Moves the finishing job past the release milestone the existing flow uses. */
function ntqRelease(Booking $job): void
{
    $job->update(['status' => 'waiting_verification', 'payment_submitted_at' => now()]);
}

function ntqActiveOffers(): int
{
    return BookingQueueOffer::active()->count();
}

// ── Eligibility ──────────────────────────────────────────────────────────────
it('offers a next task to a finishing unit even when its GPS is stale', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit, $job] = ntqFinishing($truck);
    $booking = ntqBooking($truck);

    ntqOffer($this, $dispatcher, $booking, $unit)->assertCreated()
        ->assertJsonPath('offer.state', 'awaiting_tl')
        ->assertJsonPath('offer.current_booking_code', $job->booking_code);

    $offer = BookingQueueOffer::first();
    expect($offer->status)->toBe('offered')
        ->and((int) $offer->offered_at->diffInMinutes($offer->expires_at))->toBe(10)
        ->and($booking->fresh()->assigned_unit_id)->toBeNull()
        ->and($booking->fresh()->status)->toBe('confirmed');
});

it('is off by default: no offers, no map option, nothing shown to the Team Leader', function () {
    config(['towmate.next_task_queue_enabled' => false]);
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit] = ntqFinishing($truck);
    $booking = ntqBooking($truck);

    ntqOffer($this, $dispatcher, $booking, $unit)->assertStatus(422);
    $row = collect($this->actingAs($dispatcher)->getJson(route('admin.units.locations', ['scope' => 'assignment', 'booking' => $booking->booking_code]))->json())->firstWhere('unit_id', $unit->id);
    expect($row['queue_eligible'])->toBeFalse()->and(ntqActiveOffers())->toBe(0);

    $free = ntqUnit($truck, 'Free 01');
    $rows = collect($this->actingAs($dispatcher)->getJson(route('admin.units.locations', ['scope' => 'assignment', 'booking' => $booking->booking_code]))->json());
    expect($rows->firstWhere('unit_id', $free->id)['selectable'])->toBeTrue(); // Phase A assignment unaffected

    Sanctum::actingAs($unit->teamLeader);
    $this->getJson('/api/v1/team-leader/next-offer')->assertOk()->assertJsonPath('data', null);
});

it('refuses a Team Leader who is genuinely offline, but not one who merely has stale GPS', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$stale] = ntqFinishing($truck, 'Stale GPS');         // location_updated_at is 30 min old, app reachable
    [$offline] = ntqFinishing($truck, 'Offline');
    User::whereKey($offline->team_leader_id)->update(['last_ping_at' => now()->subHour()]);
    Cache::forget("teamleader:presence:{$offline->team_leader_id}");

    ntqOffer($this, $dispatcher, ntqBooking($truck), $offline->fresh())->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'appears offline'));
    ntqOffer($this, $dispatcher, ntqBooking($truck), $stale)->assertCreated();
});

it('only offers to Team Leaders on the pilot list', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$piloted] = ntqFinishing($truck, 'Piloted');
    [$other] = ntqFinishing($truck, 'Other');

    config(['towmate.next_task_queue_team_leaders' => '']);
    ntqOffer($this, $dispatcher, ntqBooking($truck), $piloted)->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'not enabled'));

    config(['towmate.next_task_queue_team_leaders' => $piloted->team_leader_id . ', 999999']);
    ntqOffer($this, $dispatcher, ntqBooking($truck), $other)->assertStatus(422);
    ntqOffer($this, $dispatcher, ntqBooking($truck), $piloted)->assertCreated();
});

it('honours the configured expiry', function () {
    config(['towmate.queue_offer_ttl_minutes' => 3]);
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit] = ntqFinishing($truck);

    ntqOffer($this, $dispatcher, ntqBooking($truck), $unit)->assertCreated();

    $offer = BookingQueueOffer::first();
    expect((int) $offer->offered_at->diffInMinutes($offer->expires_at))->toBe(3);
});

it('refuses units that are not finishing a job', function (string $case) {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    $booking = ntqBooking($truck);

    $unit = match ($case) {
        'available' => ntqUnit($truck, 'Idle 01'),
        'on_the_way' => ntqFinishing($truck, 'Busy 01', 'on_the_way')[0],
        'on_job' => ntqFinishing($truck, 'Busy 02', 'on_job')[0],
    };

    ntqOffer($this, $dispatcher, $booking, $unit)->assertStatus(422)->assertJsonPath('reason', 'not_eligible');
    expect(ntqActiveOffers())->toBe(0);
})->with(['available', 'on_the_way', 'on_job']);

it('requires the exact tow class', function () {
    $dispatcher = ntqDispatcher();
    [$unit] = ntqFinishing(ntqTruck('NTQ Light'));
    $booking = ntqBooking(ntqTruck('NTQ Heavy'));

    ntqOffer($this, $dispatcher, $booking, $unit)->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'Exact tow class required'));
});

it('excludes group bookings and units finishing a group job', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit, $job] = ntqFinishing($truck);

    ntqOffer($this, $dispatcher, ntqBooking($truck, 'confirmed', ['group_code' => 'GRP-1']), $unit)
        ->assertStatus(422)->assertJsonPath('message', 'Group bookings cannot be queued yet.');

    $job->update(['group_code' => 'GRP-2']);
    ntqOffer($this, $dispatcher, ntqBooking($truck), $unit)->assertStatus(422);
    expect(ntqActiveOffers())->toBe(0);
});

it('only queues accepted bookings that are still unassigned', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit] = ntqFinishing($truck);

    ntqOffer($this, $dispatcher, ntqBooking($truck, 'pending'), $unit)->assertStatus(422);
    ntqOffer($this, $dispatcher, ntqBooking($truck, 'confirmed', ['assigned_unit_id' => ntqUnit($truck, 'Other')->id]), $unit)->assertStatus(422);
    expect(ntqActiveOffers())->toBe(0);
});

it('keeps the Scheduled ready window', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit] = ntqFinishing($truck);

    $later = ntqBooking($truck, 'scheduled_confirmed', [
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDays(3)->toDateString(),
        'scheduled_time' => '10:00',
    ]);
    $soon = ntqBooking($truck, 'scheduled_confirmed', [
        'service_type' => 'schedule',
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => now()->addMinutes(30)->format('H:i'),
    ]);

    ntqOffer($this, $dispatcher, $later, $unit)->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, '1 hour before'));
    ntqOffer($this, $dispatcher, $soon, $unit)->assertCreated();
});

it('refuses a unit with no driver, an off-duty Team Leader or a dispatcher override', function (string $case) {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit] = ntqFinishing($truck);

    match ($case) {
        'no_driver' => $unit->update(['driver_name' => null]),
        'off_duty' => User::whereKey($unit->team_leader_id)->update(['duty_status' => 'unavailable']),
        'override' => app(\App\Services\TeamLeaderAvailabilityService::class)->setOperationalOverride($unit->teamLeader, 'unavailable'),
    };

    ntqOffer($this, $dispatcher, ntqBooking($truck), $unit->fresh())->assertStatus(422);
    expect(ntqActiveOffers())->toBe(0);
})->with(['no_driver', 'off_duty', 'override']);

// ── One active offer, history kept ───────────────────────────────────────────
it('allows one active offer per booking, unit, Team Leader and finishing job', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit] = ntqFinishing($truck);
    [$otherUnit] = ntqFinishing($truck, 'Finishing 02');
    $booking = ntqBooking($truck);
    $second = ntqBooking($truck);

    ntqOffer($this, $dispatcher, $booking, $unit)->assertCreated();
    ntqOffer($this, $dispatcher, $booking, $otherUnit)->assertStatus(422); // booking already queued
    ntqOffer($this, $dispatcher, $second, $unit)->assertStatus(422);       // leader/unit already queued
    expect(ntqActiveOffers())->toBe(1);
});

it('enforces the same rules in the database, not only in the service', function (string $column) {
    $truck = ntqTruck();
    [$unitA, $jobA] = ntqFinishing($truck, 'A');
    [$unitB, $jobB] = ntqFinishing($truck, 'B');
    $bookingA = ntqBooking($truck);
    $bookingB = ntqBooking($truck);

    $row = fn (Unit $u, Booking $behind, Booking $b) => [
        'booking_id' => $b->id, 'behind_booking_id' => $behind->id, 'team_leader_id' => $u->team_leader_id,
        'unit_id' => $u->id, 'status' => 'offered', 'offered_at' => now(), 'expires_at' => now()->addMinutes(10),
    ];

    BookingQueueOffer::create($row($unitA, $jobA, $bookingA));
    $clash = $row($unitB, $jobB, $bookingB);
    $clash[$column] = $row($unitA, $jobA, $bookingA)[$column];

    expect(fn () => BookingQueueOffer::create($clash))->toThrow(UniqueConstraintViolationException::class);
})->with(['booking_id', 'team_leader_id', 'unit_id', 'behind_booking_id']);

it('keeps rejected, cancelled and expired offers as history and allows a new offer', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit] = ntqFinishing($truck);
    $booking = ntqBooking($truck);

    foreach (['rejected', 'cancelled', 'expired'] as $terminal) {
        $id = ntqOffer($this, $dispatcher, $booking, $unit)->assertCreated()->json('offer.id');
        BookingQueueOffer::whereKey($id)->update(['status' => $terminal]);
    }

    ntqOffer($this, $dispatcher, $booking, $unit)->assertCreated();
    expect(BookingQueueOffer::count())->toBe(4)->and(ntqActiveOffers())->toBe(1);
});

it('turns a lost insert race into a 409 and leaves exactly one offer', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit, $job] = ntqFinishing($truck);
    [$rival] = ntqFinishing($truck, 'Rival');
    $booking = ntqBooking($truck);
    $injected = false;
    $rivalJobId = Booking::where('assigned_unit_id', $rival->id)->value('id');

    // After our checks have passed but before our INSERT (the last check is on the finishing job),
    // a competing request queues the same booking for another unit.
    DB::beforeExecuting(function (string $sql) use (&$injected, $booking, $rival, $rivalJobId) {
        if (! $injected && str_starts_with(strtolower($sql), 'select exists') && str_contains($sql, '"behind_booking_id" = ?')) {
            $injected = true;
            DB::table('booking_queue_offers')->insert([
                'booking_id' => $booking->id, 'behind_booking_id' => $rivalJobId,
                'team_leader_id' => $rival->team_leader_id, 'unit_id' => $rival->id, 'status' => 'offered',
                'offered_at' => now(), 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    ntqOffer($this, $dispatcher, $booking, $unit)->assertStatus(409)->assertJsonPath('reason', 'conflict');
    expect(ntqActiveOffers())->toBe(1)->and(BookingQueueOffer::first()->unit_id)->toBe($rival->id);
});

// ── Normal assignment cannot bypass a reservation ────────────────────────────
it('blocks direct assignment of a queued booking and of a reserved unit', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit] = ntqFinishing($truck);
    $booking = ntqBooking($truck);
    $other = ntqBooking($truck);
    ntqOffer($this, $dispatcher, $booking, $unit)->assertCreated();

    $free = ntqUnit($truck, 'Free 01');
    $payload = fn (Unit $u) => ['action' => 'accept', 'assigned_unit_id' => $u->id, 'final_total' => 4536, 'additional_fee' => 0, 'distance_fee' => 0, 'base_rate' => 1500, 'per_km_rate' => 300];

    $this->actingAs($dispatcher)->postJson(route('admin.booking.assign', $booking->booking_code), $payload($free))
        ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'queued'));

    // Reserved unit is unavailable to everyone, including Book Now readiness.
    $evaluation = app(\App\Services\UnitAvailabilityService::class)->evaluate($unit->fresh());
    expect($evaluation['reasons'])->toContain('queue_reserved')->and($evaluation['available'])->toBeFalse();

    $row = collect($this->actingAs($dispatcher)->getJson(route('admin.units.locations', ['scope' => 'assignment', 'booking' => $other->booking_code]))->json())->firstWhere('unit_id', $unit->id);
    expect($row['selectable'])->toBeFalse()->and($row['queue_eligible'])->toBeFalse();
});

it('exposes queue fields on the live-map rows', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit, $job] = ntqFinishing($truck);
    $idle = ntqUnit($truck, 'Idle 01');
    $booking = ntqBooking($truck);

    $rows = collect($this->actingAs($dispatcher)->getJson(route('admin.units.locations', ['scope' => 'assignment', 'booking' => $booking->booking_code]))->assertOk()->json());
    $finishing = $rows->firstWhere('unit_id', $unit->id);
    $available = $rows->firstWhere('unit_id', $idle->id);

    expect($finishing['queue_eligible'])->toBeTrue()
        ->and($finishing['queue_current_booking'])->toBe($job->booking_code)
        ->and($finishing['selectable'])->toBeFalse()   // "Assign Now" stays exclusive to available units
        ->and($finishing['phase'])->toBe('finishing')
        ->and($available['selectable'])->toBeTrue()
        ->and($available['queue_eligible'])->toBeFalse();
});

// ── Team Leader accept / decline ─────────────────────────────────────────────
it('lets the Team Leader see, accept and decline their offer', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit, $job] = ntqFinishing($truck);
    $booking = ntqBooking($truck);
    $offerId = ntqOffer($this, $dispatcher, $booking, $unit)->json('offer.id');

    Sanctum::actingAs($unit->teamLeader);
    $shown = $this->getJson('/api/v1/team-leader/next-offer')->assertOk()->json('data');
    expect($shown['booking']['booking_code'])->toBe($booking->booking_code)
        ->and($shown['seconds_left'])->toBeGreaterThan(500)
        ->and($shown['current_booking_code'])->toBe($job->booking_code)
        ->and(json_encode($shown))->not->toContain('ntq@example.com')->not->toContain('09170000000');

    $this->postJson("/api/v1/team-leader/next-offer/{$offerId}/accept")->assertOk();
    $this->postJson("/api/v1/team-leader/next-offer/{$offerId}/accept")->assertOk(); // idempotent
    expect(BookingQueueOffer::find($offerId)->status)->toBe('accepted');

    // Accepting only reserves: nothing was assigned, the active task is unchanged.
    expect($booking->fresh()->assigned_unit_id)->toBeNull()->and($booking->fresh()->status)->toBe('confirmed');
    $this->getJson('/api/v1/team-leader/task')->assertOk()->assertJsonPath('data.booking_code', $job->booking_code);

    // Accepted can still be released by declining; that frees the booking and the unit.
    $this->postJson("/api/v1/team-leader/next-offer/{$offerId}/decline")->assertOk();
    expect(BookingQueueOffer::find($offerId)->status)->toBe('rejected')->and(ntqActiveOffers())->toBe(0);
    $this->postJson("/api/v1/team-leader/next-offer/{$offerId}/accept")->assertStatus(409);
});

it('releases the reservation when the Team Leader declines', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit] = ntqFinishing($truck);
    $booking = ntqBooking($truck);
    $offerId = ntqOffer($this, $dispatcher, $booking, $unit)->json('offer.id');

    Sanctum::actingAs($unit->teamLeader);
    $this->postJson("/api/v1/team-leader/next-offer/{$offerId}/decline")->assertOk();
    $this->postJson("/api/v1/team-leader/next-offer/{$offerId}/decline")->assertOk(); // idempotent
    $this->getJson('/api/v1/team-leader/next-offer')->assertOk()->assertJsonPath('data', null);

    expect(BookingQueueOffer::find($offerId)->status)->toBe('rejected')->and(ntqActiveOffers())->toBe(0);
    ntqOffer($this, $dispatcher, $booking, $unit)->assertCreated(); // free to offer again
});

it('does not let anyone else answer or read an offer', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit] = ntqFinishing($truck);
    [$intruderUnit] = ntqFinishing($truck, 'Intruder');
    $offerId = ntqOffer($this, $dispatcher, ntqBooking($truck), $unit)->json('offer.id');

    Sanctum::actingAs($intruderUnit->teamLeader);
    $this->postJson("/api/v1/team-leader/next-offer/{$offerId}/accept")->assertNotFound();
    $this->getJson('/api/v1/team-leader/next-offer')->assertOk()->assertJsonPath('data', null);

    Sanctum::actingAs($dispatcher);
    $this->postJson("/api/v1/team-leader/next-offer/{$offerId}/accept")->assertForbidden();

    $customer = User::factory()->create(['role_id' => 5]);
    Sanctum::actingAs($customer);
    $this->postJson("/api/v1/team-leader/next-offer/{$offerId}/accept")->assertForbidden();

    expect(BookingQueueOffer::find($offerId)->status)->toBe('offered');
});

it('rejects unauthenticated queue calls', function () {
    $this->postJson('/api/v1/team-leader/next-offer/1/accept')->assertUnauthorized();
    $this->postJson('/admin-dashboard/queue-offers/1/promote')->assertUnauthorized();
});

it('keeps dispatcher queue endpoints away from other roles', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit] = ntqFinishing($truck);
    $booking = ntqBooking($truck);
    $offerId = ntqOffer($this, $dispatcher, $booking, $unit)->json('offer.id');

    foreach ([$unit->teamLeader, User::factory()->create(['role_id' => 5])] as $user) {
        $this->actingAs($user);
        $this->postJson(route('admin.queue-offers.cancel', $offerId))->assertForbidden();
        $this->postJson(route('admin.queue-offers.promote', $offerId))->assertForbidden();
        $this->postJson(route('admin.booking.queue-offer.store', $booking->booking_code), ['unit_id' => $unit->id])->assertForbidden();
    }
    expect(BookingQueueOffer::find($offerId)->status)->toBe('offered');
});

// ── Expiry and cancellation ──────────────────────────────────────────────────
it('answers an expired offer without accepting it and frees the booking', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit] = ntqFinishing($truck);
    $booking = ntqBooking($truck);
    $offerId = ntqOffer($this, $dispatcher, $booking, $unit)->json('offer.id');

    $this->travel(11)->minutes();
    Sanctum::actingAs($unit->teamLeader);
    $this->postJson("/api/v1/team-leader/next-offer/{$offerId}/accept")->assertStatus(409)->assertJsonPath('reason', 'expired');
    expect(BookingQueueOffer::find($offerId)->status)->toBe('expired')->and(ntqActiveOffers())->toBe(0);
});

it('expires overdue offers from the scheduled command', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit] = ntqFinishing($truck);
    ntqOffer($this, $dispatcher, ntqBooking($truck), $unit)->assertCreated();

    $this->travel(11)->minutes();
    $this->artisan('queue:expire-offers')->assertSuccessful();
    expect(BookingQueueOffer::first()->status)->toBe('expired');
    expect(app(\App\Services\UnitAvailabilityService::class)->evaluate($unit->fresh())['reasons'])->not->toContain('queue_reserved');
});

it('lets the dispatcher cancel, idempotently, and records who and why', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit] = ntqFinishing($truck);
    $offerId = ntqOffer($this, $dispatcher, ntqBooking($truck), $unit)->json('offer.id');

    $this->actingAs($dispatcher)->postJson(route('admin.queue-offers.cancel', $offerId))->assertOk();
    $this->actingAs($dispatcher)->postJson(route('admin.queue-offers.cancel', $offerId))->assertOk();

    $offer = BookingQueueOffer::find($offerId);
    expect($offer->status)->toBe('cancelled')->and($offer->cancelled_by)->toBe($dispatcher->id);

    Sanctum::actingAs($unit->teamLeader);
    $this->postJson("/api/v1/team-leader/next-offer/{$offerId}/accept")->assertStatus(409);
});

it('cancels an offer automatically when the booking is no longer waiting for a unit', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit] = ntqFinishing($truck);
    $booking = ntqBooking($truck);
    $offerId = ntqOffer($this, $dispatcher, $booking, $unit)->json('offer.id');

    $booking->update(['status' => 'cancelled']);
    Sanctum::actingAs($unit->teamLeader);
    $this->getJson('/api/v1/team-leader/next-offer')->assertOk()->assertJsonPath('data', null);
    expect(BookingQueueOffer::find($offerId)->status)->toBe('cancelled');
});

// ── Promotion ────────────────────────────────────────────────────────────────
it('promotes only after the Team Leader accepted and the current job is released', function () {
    Event::fake([BookingStatusUpdated::class]);
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit, $job] = ntqFinishing($truck);
    $booking = ntqBooking($truck);
    $offerId = ntqOffer($this, $dispatcher, $booking, $unit)->json('offer.id');
    $promote = fn () => $this->actingAs($dispatcher)->postJson(route('admin.queue-offers.promote', $offerId));

    $promote()->assertStatus(409); // not accepted yet

    Sanctum::actingAs($unit->teamLeader);
    $this->postJson("/api/v1/team-leader/next-offer/{$offerId}/accept")->assertOk();

    $promote()->assertStatus(409)->assertJsonPath('reason', 'not_ready'); // still finishing
    expect(ntqActiveOffers())->toBe(1)->and($booking->fresh()->status)->toBe('confirmed');

    $state = fn () => $this->actingAs($dispatcher)->getJson(route('admin.booking.queue-offer', $booking->booking_code))->json('offer');
    expect($state()['state'])->toBe('reserved')->and($state()['can_promote'])->toBeFalse();

    ntqRelease($job); // payment submitted: the existing release milestone
    expect($state()['state'])->toBe('ready')->and($state()['can_promote'])->toBeTrue();

    $promote()->assertOk()->assertJsonPath('booking_code', $booking->booking_code);
    $promote()->assertOk(); // idempotent

    $booking->refresh();
    expect($booking->status)->toBe('assigned')
        ->and($booking->assigned_unit_id)->toBe($unit->id)
        ->and($booking->assigned_team_leader_id)->toBe($unit->team_leader_id)
        ->and(BookingQueueOffer::first()->status)->toBe('promoted')
        ->and(ntqActiveOffers())->toBe(0);
    Event::assertDispatchedTimes(BookingStatusUpdated::class, 1);

    // The old job's payment verification is untouched, and the TL now accepts through the existing flow.
    expect($job->fresh()->status)->toBe('waiting_verification');
    Sanctum::actingAs($unit->teamLeader);
    $this->postJson("/api/v1/team-leader/task/{$booking->booking_code}/accept")->assertOk();
    expect($booking->fresh()->status)->toBe('accepted');
});

it('revalidates everything at promotion time', function (string $case) {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit, $job] = ntqFinishing($truck);
    $booking = ntqBooking($truck);
    $offerId = ntqOffer($this, $dispatcher, $booking, $unit)->json('offer.id');
    Sanctum::actingAs($unit->teamLeader);
    $this->postJson("/api/v1/team-leader/next-offer/{$offerId}/accept")->assertOk();
    ntqRelease($job);

    match ($case) {
        'driver_removed' => $unit->update(['driver_name' => null]),
        'maintenance' => $unit->update(['status' => 'maintenance']),
        'class_changed' => $unit->update(['truck_type_id' => ntqTruck('NTQ Other')->id]),
        'leader_changed' => $unit->update(['team_leader_id' => User::factory()->create(['role_id' => 3])->id]),
        'new_job' => ntqBooking($truck, 'on_the_way', ['assigned_unit_id' => $unit->id, 'assigned_team_leader_id' => $unit->team_leader_id]),
        'booking_assigned_elsewhere' => $booking->update(['assigned_unit_id' => ntqUnit($truck, 'Other')->id, 'status' => 'assigned']),
    };

    $this->actingAs($dispatcher)->postJson(route('admin.queue-offers.promote', $offerId))->assertStatus(409);
    expect($booking->fresh()->assigned_team_leader_id)->not->toBe($unit->team_leader_id);
})->with(['driver_removed', 'maintenance', 'class_changed', 'leader_changed', 'new_job', 'booking_assigned_elsewhere']);

it('never overlaps tasks: a second job cannot be queued behind a promoted one without finishing', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit, $job] = ntqFinishing($truck);
    $booking = ntqBooking($truck);
    $offerId = ntqOffer($this, $dispatcher, $booking, $unit)->json('offer.id');
    Sanctum::actingAs($unit->teamLeader);
    $this->postJson("/api/v1/team-leader/next-offer/{$offerId}/accept")->assertOk();
    ntqRelease($job);
    $this->actingAs($dispatcher)->postJson(route('admin.queue-offers.promote', $offerId))->assertOk();

    // The promoted task is now the unit's assigned job: nothing else queues behind it.
    ntqOffer($this, $dispatcher, ntqBooking($truck), $unit)->assertStatus(422);
    expect(ntqActiveOffers())->toBe(0);
});

it('records the lifecycle in the audit log', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    [$unit, $job] = ntqFinishing($truck);
    $booking = ntqBooking($truck);
    $offerId = ntqOffer($this, $dispatcher, $booking, $unit)->json('offer.id');
    Sanctum::actingAs($unit->teamLeader);
    $this->postJson("/api/v1/team-leader/next-offer/{$offerId}/accept")->assertOk();
    ntqRelease($job);
    $this->actingAs($dispatcher)->postJson(route('admin.queue-offers.promote', $offerId))->assertOk();

    expect(AuditLog::where('reference', $booking->booking_code)->pluck('action')->all())
        ->toContain('queue_offer_created', 'queue_offer_accepted', 'queue_offer_promoted');
});

it('keeps existing Return behaviour: a returned task still needs a normal assignment', function () {
    $dispatcher = ntqDispatcher();
    $truck = ntqTruck();
    $returned = ntqBooking($truck, 'confirmed', ['needs_reassignment' => true]);
    $free = ntqUnit($truck, 'Free 01');

    $rows = collect($this->actingAs($dispatcher)->getJson(route('admin.units.locations', ['scope' => 'assignment', 'booking' => $returned->booking_code]))->assertOk()->json());
    expect($rows->firstWhere('unit_id', $free->id)['selectable'])->toBeTrue();
});

it('throws typed exceptions from the service', function () {
    $dispatcher = ntqDispatcher();
    expect(fn () => app(NextTaskQueueService::class)->cancel(999999, $dispatcher))->toThrow(QueueOfferException::class);
});
