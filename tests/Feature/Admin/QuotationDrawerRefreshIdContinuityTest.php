<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\PriceAdjustment;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

function qidDispatcher(): User
{
    $role = Role::find(2) ?: tap(new Role(['name' => 'Dispatcher']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id]);
}

function qidCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'QID Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'qid-' . uniqid() . '@example.com',
    ]);
}

function qidTruckType(float $baseRate, float $perKmRate): TruckType
{
    return TruckType::create([
        'name' => 'QID Truck ' . fake()->unique()->word(),
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

function qidBooking(Customer $customer, TruckType $truckType, string $groupCode, float $distanceKm = 12.0): Booking
{
    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'group_code' => $groupCode,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => $distanceKm,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'status' => 'scheduled',
        'service_type' => 'schedule',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
    ]);

    return $booking->fresh(['customer', 'truckType']);
}

function qidDraft(User $dispatcher, Booking $booking, float $price)
{
    return test()->actingAs($dispatcher)->post(route('admin.booking.save-draft', $booking), [
        'price' => (string) $price,
        'distance_km' => (string) $booking->distance_km,
    ]);
}

it('returns the same quotation_id from sendQuotation and the follow-up details fetch shows the new Quotation sent event', function () {
    Mail::fake();
    $dispatcher = qidDispatcher();
    $customer = qidCustomer();
    $truck = qidTruckType(1500, 60);
    $booking = qidBooking($customer, $truck, 'QID-1', 12.0);

    qidDraft($dispatcher, $booking, 2217.6)->assertOk();
    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();
    $originalId = $quotation->id;

    $response = test()->actingAs($dispatcher)->postJson(route('admin.quotations.send', $quotation));
    $response->assertOk();

    expect($response->json('quotation_id'))->toBe($originalId);

    $details = test()->actingAs($dispatcher)->getJson(route('admin.quotations.details', $originalId));
    $details->assertOk();

    $sentEntries = collect($details->json('quotation.price_change_log'))->where('type', 'quotation_sent');
    expect($sentEntries)->not->toBeEmpty();
});

it('returns the new current quotation_id from updateQuotationPrice, not the superseded one, so a follow-up fetch is never stale', function () {
    Mail::fake();
    $dispatcher = qidDispatcher();
    $customer = qidCustomer();
    $truck = qidTruckType(1500, 60);
    $booking = qidBooking($customer, $truck, 'QID-2', 12.0);

    qidDraft($dispatcher, $booking, 2217.6)->assertOk();
    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();
    test()->actingAs($dispatcher)->post(route('admin.quotations.send', $quotation))->assertOk();
    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();
    $originalId = $quotation->id;

    $response = test()->actingAs($dispatcher)->patchJson(route('admin.quotations.update-price', $quotation), [
        'new_price' => (string) round((float) $quotation->estimated_price + 300, 2),
        'note' => 'Resend at new price',
    ]);
    $response->assertOk();

    $newId = $response->json('quotation_id');
    expect($newId)->not->toBe($originalId);
    expect(Quotation::find($newId)->is_current)->toBeTrue();
    expect(Quotation::find($originalId)->is_current)->toBeFalse();

    $freshDetails = test()->actingAs($dispatcher)->getJson(route('admin.quotations.details', $newId));
    $freshDetails->assertOk();
    expect((float) $freshDetails->json('quotation.estimated_price'))->toBe((float) Quotation::find($newId)->estimated_price);

    $staleDetails = test()->actingAs($dispatcher)->getJson(route('admin.quotations.details', $originalId));
    $staleDetails->assertOk();
    expect((float) $staleDetails->json('quotation.estimated_price'))
        ->not->toBe((float) $freshDetails->json('quotation.estimated_price'));
});

it('keeps the same quotation_id from keepQuotationPrice since no new version is created', function () {
    Mail::fake();
    $dispatcher = qidDispatcher();
    $customer = qidCustomer();
    $truck = qidTruckType(1500, 60);
    $booking = qidBooking($customer, $truck, 'QID-3', 12.0);

    qidDraft($dispatcher, $booking, 2217.6)->assertOk();
    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();
    test()->actingAs($dispatcher)->post(route('admin.quotations.send', $quotation))->assertOk();
    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();

    $quotation->update(['status' => 'price_review_requested']);
    $originalId = $quotation->id;

    $response = test()->actingAs($dispatcher)->postJson(route('admin.quotations.keep-price', $quotation));
    $response->assertOk();

    expect($response->json('quotation_id'))->toBe($originalId);
});

it('returns the new current quotation_id from adjustQuotationPriceAfterReview after a price review', function () {
    Mail::fake();
    $dispatcher = qidDispatcher();
    $customer = qidCustomer();
    $truck = qidTruckType(1500, 60);
    $booking = qidBooking($customer, $truck, 'QID-4', 12.0);

    qidDraft($dispatcher, $booking, 2217.6)->assertOk();
    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();
    test()->actingAs($dispatcher)->post(route('admin.quotations.send', $quotation))->assertOk();
    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();

    $quotation->update(['status' => 'price_review_requested']);
    $originalId = $quotation->id;

    $response = test()->actingAs($dispatcher)->postJson(route('admin.quotations.adjust-price', $quotation), [
        'new_price' => (string) round((float) $quotation->estimated_price - 100, 2),
        'note' => 'Reviewed and reduced',
    ]);
    $response->assertOk();

    $newId = $response->json('quotation_id');
    expect($newId)->not->toBeNull();
    expect($newId)->not->toBe($originalId);
    expect(Quotation::find($newId)->is_current)->toBeTrue();
});

it('returns the new current quotation_id from undoPriceAdjustment on a sent quotation, matching what getQuotationDetails needs', function () {
    Mail::fake();
    $dispatcher = qidDispatcher();
    $customer = qidCustomer();
    $truck = qidTruckType(1500, 60);
    $booking = qidBooking($customer, $truck, 'QID-5', 12.0);

    qidDraft($dispatcher, $booking, 2217.6)->assertOk();
    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();
    test()->actingAs($dispatcher)->post(route('admin.quotations.send', $quotation))->assertOk();
    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();

    test()->actingAs($dispatcher)->patchJson(route('admin.quotations.update-price', $quotation), [
        'new_price' => (string) round((float) $quotation->estimated_price + 300, 2),
        'note' => 'Extra charge',
    ])->assertOk();

    $quotation = Quotation::where('quotation_number', $quotation->quotation_number)->current()->first();
    $adjustment = PriceAdjustment::where('quotation_number', $quotation->quotation_number)->first();
    expect($adjustment)->not->toBeNull();
    $beforeUndoId = $quotation->id;

    $response = test()->actingAs($dispatcher)->postJson(route('admin.quotations.adjustments.undo', [
        'quotation' => $quotation->id,
        'adjustment' => $adjustment->id,
    ]));
    $response->assertOk();

    $newId = $response->json('quotation_id');
    expect($newId)->not->toBe($beforeUndoId);
    expect(Quotation::find($newId)->is_current)->toBeTrue();

    $details = test()->actingAs($dispatcher)->getJson(route('admin.quotations.details', $newId));
    $details->assertOk();
    expect((float) $details->json('quotation.additional_fee'))->toBe(0.0);
});

it('keeps the same quotation_id from undoPriceAdjustment on a still-draft quotation, since drafts are updated in place', function () {
    $dispatcher = qidDispatcher();
    $customer = qidCustomer();
    $truck = qidTruckType(1500, 60);
    $booking = qidBooking($customer, $truck, 'QID-6', 12.0);

    qidDraft($dispatcher, $booking, 2517.6, )->assertOk();
    test()->actingAs($dispatcher)->post(route('admin.booking.save-draft', $booking), [
        'price' => '2517.6',
        'distance_km' => (string) $booking->distance_km,
        'adjustments' => [
            ['type' => 'add', 'amount' => 300, 'reason' => 'Extra'],
        ],
    ])->assertOk();

    $quotation = Quotation::where('source_booking_id', $booking->id)->current()->first();
    $adjustment = PriceAdjustment::where('quotation_number', $quotation->quotation_number)->first();
    expect($adjustment)->not->toBeNull();
    $originalId = $quotation->id;

    $response = test()->actingAs($dispatcher)->postJson(route('admin.quotations.adjustments.undo', [
        'quotation' => $quotation->id,
        'adjustment' => $adjustment->id,
    ]));
    $response->assertOk();

    expect($response->json('quotation_id'))->toBe($originalId);
});
