<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\TruckType;
use App\Models\User;
use App\Services\BookingService;
use App\Services\QuotationService;
use Illuminate\Support\Facades\DB;

function bschdRoles(): void
{
    DB::table('roles')->insertOrIgnore([
        ['id' => 1, 'name' => 'Owner', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'name' => 'Admin', 'created_at' => now(), 'updated_at' => now()],
    ]);
}

function bschdDispatcher(): User
{
    bschdRoles();

    return User::factory()->create(['role_id' => 2]);
}

function bschdCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'BSCHD Customer ' . uniqid(),
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'bschd-' . uniqid() . '@example.test',
    ]);
}

function bschdTruckType(string $name = null): TruckType
{
    return TruckType::create([
        'name' => $name ?? 'BSCHD Truck ' . uniqid(),
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'max_tonnage' => 5,
        'status' => 'active',
    ]);
}

function bschdScheduledBooking(array $overrides = []): Booking
{
    $customer = $overrides['customer'] ?? bschdCustomer();
    $truckType = $overrides['truck_type'] ?? bschdTruckType();
    $date = $overrides['scheduled_date'] ?? now()->addDays(2)->toDateString();
    $time = $overrides['scheduled_time'] ?? '14:00';

    return Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'BSCHD Pickup',
        'dropoff_address' => 'BSCHD Dropoff',
        'distance_km' => 10,
        'base_rate' => 1500,
        'per_km_rate' => 75,
        'computed_total' => 2250,
        'final_total' => 2250,
        'service_type' => 'schedule',
        'scheduled_date' => $date,
        'scheduled_time' => $time,
        'scheduled_for' => Carbon\Carbon::parse("$date $time"),
        'status' => $overrides['status'] ?? 'scheduled_confirmed',
        'group_code' => $overrides['group_code'] ?? null,
    ]);
}

test('Schedule Later booking creation keeps scheduled_date, scheduled_time, and scheduled_for synchronized', function () {
    $customer = bschdCustomer();
    $truckType = bschdTruckType();

    $booking = app(BookingService::class)->createBooking([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pickup A',
        'dropoff_address' => 'Dropoff B',
        'distance_km' => 8,
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '09:30',
    ]);

    $raw = DB::table('bookings')->where('id', $booking->id)->first();

    expect($raw->scheduled_date)->not->toBeNull();
    expect($raw->scheduled_time)->toBe('09:30');
    expect($raw->scheduled_for)->not->toBeNull();
    expect(Carbon\Carbon::parse($raw->scheduled_for)->format('Y-m-d H:i'))
        ->toBe(Carbon\Carbon::parse($raw->scheduled_date . ' ' . $raw->scheduled_time)->format('Y-m-d H:i'));
});

test('Book Now booking creation leaves scheduled_date, scheduled_time, and scheduled_for null', function () {
    $customer = bschdCustomer();
    $truckType = bschdTruckType();

    $booking = app(BookingService::class)->createBooking([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pickup A',
        'dropoff_address' => 'Dropoff B',
        'distance_km' => 8,
        'service_type' => 'book_now',
    ]);

    $raw = DB::table('bookings')->where('id', $booking->id)->first();

    expect($raw->scheduled_date)->toBeNull();
    expect($raw->scheduled_time)->toBeNull();
    expect($raw->scheduled_for)->toBeNull();
});

test('dispatcher reschedule updates the persisted scheduled_for column, not just the accessor fallback', function () {
    $dispatcher = bschdDispatcher();
    $booking = bschdScheduledBooking(['scheduled_date' => now()->addDay()->toDateString(), 'scheduled_time' => '08:00']);

    $newDate = now()->addDays(5)->toDateString();
    $newTime = '16:45';

    $response = $this->actingAs($dispatcher)->post(route('admin.booking.reschedule', $booking), [
        'new_scheduled_date' => $newDate,
        'new_scheduled_time' => $newTime,
        'reason' => 'Customer requested a later slot',
    ]);

    $response->assertOk()->assertJson(['success' => true]);

    $raw = DB::table('bookings')->where('id', $booking->id)->first();

    expect($raw->scheduled_date)->toBe($newDate);
    expect($raw->scheduled_time)->toBe($newTime);
    expect($raw->scheduled_for)->not->toBeNull();
    expect(Carbon\Carbon::parse($raw->scheduled_for)->format('Y-m-d H:i'))
        ->toBe(Carbon\Carbon::parse("$newDate $newTime")->format('Y-m-d H:i'));
});

test('rescheduling does not change the booking status', function () {
    $dispatcher = bschdDispatcher();
    $booking = bschdScheduledBooking(['scheduled_date' => now()->addDay()->toDateString()]);

    $this->actingAs($dispatcher)->post(route('admin.booking.reschedule', $booking), [
        'new_scheduled_date' => now()->addDays(3)->toDateString(),
        'new_scheduled_time' => '10:00',
    ])->assertOk();

    expect($booking->fresh()->status)->toBe('scheduled_confirmed');
});

test('rescheduling a non-confirmed-scheduled booking is rejected and does not touch scheduled_for', function () {
    $dispatcher = bschdDispatcher();
    $booking = bschdScheduledBooking(['status' => 'requested']);
    $originalScheduledFor = DB::table('bookings')->where('id', $booking->id)->value('scheduled_for');

    $response = $this->actingAs($dispatcher)->post(route('admin.booking.reschedule', $booking), [
        'new_scheduled_date' => now()->addDays(3)->toDateString(),
        'new_scheduled_time' => '10:00',
    ]);

    $response->assertStatus(422);
    expect(DB::table('bookings')->where('id', $booking->id)->value('scheduled_for'))->toEqual($originalScheduledFor);
});

test('accepting a quotation creates a scheduled booking with a synchronized scheduled_for column', function () {
    $customer = bschdCustomer();
    $truckType = bschdTruckType();
    $date = now()->addDays(3)->toDateString();
    $time = '11:15';

    $quotation = Quotation::create([
        'quotation_number' => 'BSCHD-' . uniqid(),
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pickup A',
        'dropoff_address' => 'Dropoff B',
        'distance_km' => 10,
        'estimated_price' => 2500,
        'service_type' => 'schedule',
        'scheduled_date' => $date,
        'scheduled_time' => $time,
        'status' => 'sent',
        'is_current' => true,
        'sent_at' => now(),
    ]);

    $booking = app(QuotationService::class)->acceptQuotation($quotation);

    $raw = DB::table('bookings')->where('id', $booking->id)->first();

    expect($raw->status)->toBe('scheduled_confirmed');
    expect($raw->scheduled_date)->not->toBeNull();
    expect($raw->scheduled_time)->toBe($time);
    expect($raw->scheduled_for)->not->toBeNull();
    expect(Carbon\Carbon::parse($raw->scheduled_for)->format('Y-m-d H:i'))
        ->toBe(Carbon\Carbon::parse($raw->scheduled_date . ' ' . $raw->scheduled_time)->format('Y-m-d H:i'));
});

test('accepting a grouped quotation keeps every extra-vehicle scheduled booking synchronized', function () {
    $customer = bschdCustomer();
    $truckType = bschdTruckType();
    $secondTruckType = bschdTruckType();
    $date = now()->addDays(4)->toDateString();
    $time = '13:00';
    $secondTime = '13:30';

    $quotation = Quotation::create([
        'quotation_number' => 'BSCHD-GROUP-' . uniqid(),
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => 'Pickup A',
        'dropoff_address' => 'Dropoff B',
        'distance_km' => 10,
        'estimated_price' => 2500,
        'service_type' => 'schedule',
        'scheduled_date' => $date,
        'scheduled_time' => $time,
        'status' => 'sent',
        'is_current' => true,
        'sent_at' => now(),
        'extra_vehicles' => [
            [
                'truck_type_id' => $secondTruckType->id,
                'service_type' => 'schedule',
                'scheduled_date' => $date,
                'scheduled_time' => $secondTime,
                'estimated_price' => 1800,
            ],
        ],
    ]);

    $primaryBooking = app(QuotationService::class)->acceptQuotation($quotation);

    $group = DB::table('bookings')->where('group_code', $quotation->quotation_number)->get();

    expect($group)->toHaveCount(2);

    foreach ($group as $row) {
        expect($row->scheduled_for)->not->toBeNull();
        expect(Carbon\Carbon::parse($row->scheduled_for)->format('Y-m-d H:i'))
            ->toBe(Carbon\Carbon::parse($row->scheduled_date . ' ' . $row->scheduled_time)->format('Y-m-d H:i'));
    }
});
