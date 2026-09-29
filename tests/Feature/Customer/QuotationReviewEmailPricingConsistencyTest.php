<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Support\Facades\URL;

function makeQuotationReviewBooking(array $bookingOverrides = [], array $customerOverrides = []): array
{
    $customerRole = Role::find(5);

    if (! $customerRole) {
        $customerRole = new Role(['name' => 'Customer', 'description' => 'Customer role']);
        $customerRole->id = 5;
        $customerRole->save();
    }

    $user = User::factory()->create([
        'role_id' => $customerRole->id,
        'email' => $customerOverrides['email'] ?? 'review-' . uniqid() . '@example.com',
    ]);

    $customer = Customer::create([
        'id' => $user->id,
        'full_name' => 'Review Customer',
        'age' => 30,
        'phone' => '0918' . rand(1000000, 9999999),
        'email' => $user->email,
    ]);

    $truckType = TruckType::create([
        'name' => 'Review Flatbed ' . uniqid(),
        'base_rate' => 1600,
        'per_km_rate' => 85,
        'max_tonnage' => 6,
        'description' => 'Standard towing support',
    ]);

    $quotationNumber = 'Q-REVIEW-' . uniqid();

    $booking = Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'created_by_admin_id' => null,
        'age' => 30,
        'pickup_address' => 'Makati Avenue',
        'dropoff_address' => 'BGC Taguig',
        'distance_km' => 15,
        'base_rate' => 1600,
        'per_km_rate' => 85,
        'computed_total' => 2535,
        'final_total' => 2535,
        'quotation_generated' => true,
        'quotation_number' => $quotationNumber,
        'initial_quote_path' => 'quotations/test-review.html',
        'quotation_sent_at' => now(),
        'quotation_expires_at' => now()->addDays(7),
        'status' => 'quotation_sent',
    ], $bookingOverrides));

    Quotation::create([
        'quotation_number' => $quotationNumber,
        'source_booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => $booking->pickup_address,
        'dropoff_address' => $booking->dropoff_address,
        'distance_km' => $booking->distance_km,
        'estimated_price' => $booking->final_total,
        'service_type' => 'book_now',
        'status' => 'sent',
        'sent_at' => now(),
        'expires_at' => now()->addDays(7),
        'is_current' => true,
        'version' => 1,
    ]);

    return [$user, $customer, $booking->fresh(['customer', 'truckType'])];
}

it('does not show a phantom excess distance fee for a booking over the legacy 10km threshold', function () {
    SystemSetting::setValue('excess_km_threshold', '10');
    SystemSetting::setValue('excess_km_rate', '20');

    [, , $booking] = makeQuotationReviewBooking(['distance_km' => 15]);

    $signedUrl = URL::temporarySignedRoute(
        'quotation.review',
        now()->addMinutes(30),
        ['booking' => $booking]
    );

    $this->get($signedUrl)
        ->assertOk()
        ->assertDontSee('Excess distance fee')
        ->assertSee('₱' . number_format((float) $booking->final_total, 2), false);
});

it('shows the correct authoritative total for a normal short-distance booking email', function () {
    SystemSetting::setValue('excess_km_threshold', '10');
    SystemSetting::setValue('excess_km_rate', '20');

    [, , $booking] = makeQuotationReviewBooking([
        'distance_km' => 6,
        'computed_total' => 1770,
        'final_total' => 1770,
    ]);

    $this->get(URL::temporarySignedRoute(
        'quotation.review',
        now()->addMinutes(30),
        ['booking' => $booking]
    ))
        ->assertOk()
        ->assertDontSee('Excess distance fee')
        ->assertSee('₱' . number_format((float) $booking->final_total, 2), false);
});

it('keeps grouped sibling booking emails free of the phantom excess distance fee', function () {
    SystemSetting::setValue('excess_km_threshold', '10');
    SystemSetting::setValue('excess_km_rate', '20');

    $groupCode = 'GRP-' . uniqid();

    [, , $bookingOne] = makeQuotationReviewBooking([
        'group_code' => $groupCode,
        'distance_km' => 18,
    ]);

    [, , $bookingTwo] = makeQuotationReviewBooking([
        'group_code' => $groupCode,
        'distance_km' => 22,
        'computed_total' => 2870,
        'final_total' => 2870,
    ]);

    foreach ([$bookingOne, $bookingTwo] as $sibling) {
        $this->get(URL::temporarySignedRoute(
            'quotation.review',
            now()->addMinutes(30),
            ['booking' => $sibling]
        ))
            ->assertOk()
            ->assertDontSee('Excess distance fee')
            ->assertSee('₱' . number_format((float) $sibling->final_total, 2), false);
    }
});

it('confirms no live code path still references the removed excess distance accessors', function () {
    expect(method_exists(Booking::class, 'getExcessKmAttribute'))->toBeFalse()
        ->and(method_exists(Booking::class, 'getExcessFeeAmountAttribute'))->toBeFalse();

    [, , $booking] = makeQuotationReviewBooking(['distance_km' => 15]);

    expect($booking->quotation_breakdown)
        ->not->toHaveKey('excess_km')
        ->not->toHaveKey('excess_fee');
});
