<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Services\DocumentGenerationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

// completeGroup() always emails the consolidated invoice via an
// app()->terminating() callback that renders a real PDF and forces the
// output buffers closed (see the ob_end_flush()/fastcgi_finish_request()
// dance in TLTaskController::emailInvoice()) — the total this test suite
// verifies is already computed and persisted synchronously, before that
// callback ever runs, so faking both the mailer and the PDF renderer keeps
// these tests fast and isolated from that unrelated background side effect.
beforeEach(function () {
    Mail::fake();
    test()->mock(DocumentGenerationService::class, function ($mock) {
        $mock->shouldReceive('generateInvoice')->andReturn('fake/invoice.pdf');
    });
});

/**
 * Regression coverage for the completeGroup() consolidated-total fix:
 * the invoice total must always be recomputed from active member
 * Booking.final_total + quotation.additional_fee - quotation.discount,
 * never quotation.estimated_price (a frozen acceptance-time snapshot that
 * silently dropped legitimate post-acceptance per-vehicle corrections
 * whenever nobody in the group happened to be cancelled).
 */
function gctcTeamLeader(): User
{
    $role = Role::find(3) ?: tap(new Role(['name' => 'Team Leader']), function ($r) {
        $r->id = 3;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id, 'must_change_password' => false]);
}

function gctcDispatcher(): User
{
    $role = Role::find(2) ?: tap(new Role(['name' => 'Dispatcher']), function ($r) {
        $r->id = 2;
        $r->save();
    });

    return User::factory()->create(['role_id' => $role->id, 'status' => 'active', 'must_change_password' => false]);
}

function gctcCustomer(): Customer
{
    return Customer::create([
        'full_name' => 'GCTC Customer',
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => 'gctc-' . uniqid() . '@example.com',
    ]);
}

function gctcTruckType(): TruckType
{
    return TruckType::create([
        'name' => 'GCTC Truck ' . fake()->unique()->word(),
        'base_rate' => 4000,
        'per_km_rate' => 0,
    ]);
}

/**
 * gross 4000, 0% discount, 12% VAT -> vat_exclusive_total 4000, vat_amount
 * 480, final_total 4480 (matches BookingService's default 12% VAT rate,
 * same convention already used by ActiveBookingPricingLimitsTest).
 */
function gctcMember(Customer $customer, TruckType $truckType, string $groupCode, array $overrides = []): Booking
{
    $booking = Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'group_code' => $groupCode,
        'pickup_address' => 'GCTC Pickup Point',
        'dropoff_address' => 'GCTC Dropoff Point',
        'distance_km' => 10,
        'base_rate' => 4000,
        'per_km_rate' => 0,
        'computed_total' => 4000,
        'discount_percentage' => 0,
        'additional_fee' => 0,
        'vat_exclusive_total' => 4000,
        'vat_amount' => 480,
        'final_total' => 4480,
        'status' => 'arrived_dropoff',
        'service_type' => 'book_now',
    ], $overrides));
    $booking->update(['booking_code' => 'TM-GCTC' . str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT)]);

    return $booking->fresh();
}

/**
 * 3-vehicle normalized group: quotation.additional_fee 2000, discount 440
 * (net +1560), summed with 3 x 4480 member final_total = 15,000 accepted
 * total — matching QuotationService::undoPriceAdjustment()'s identical
 * groupServiceTotal + additional_fee - discount composition, so this is
 * exactly what quotation.estimated_price would also hold for an untouched
 * group.
 */
function gctcScenario(bool $withCancelledSibling = false): array
{
    $teamLeader = gctcTeamLeader();
    $customer = gctcCustomer();
    $truckType = gctcTruckType();
    $groupCode = 'GRP-GCTC-' . uniqid();

    $unit = Unit::create([
        'name' => 'GCTC Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'on_job',
    ]);

    $vehicle1 = gctcMember($customer, $truckType, $groupCode, [
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $teamLeader->id,
        'payment_proof_path' => 'task-photos/gctc-proof.jpg',
    ]);
    $vehicle2 = gctcMember($customer, $truckType, $groupCode);
    $vehicle3 = gctcMember($customer, $truckType, $groupCode);

    $extraVehicles = [
        ['booking_id' => $vehicle2->id, 'truck_type_id' => $truckType->id],
        ['booking_id' => $vehicle3->id, 'truck_type_id' => $truckType->id],
    ];

    if ($withCancelledSibling) {
        $vehicle4 = gctcMember($customer, $truckType, $groupCode, [
            'final_total' => 9999,
            'status' => 'cancelled',
        ]);
        $extraVehicles[] = ['booking_id' => $vehicle4->id, 'truck_type_id' => $truckType->id];
    }

    $quotation = Quotation::create([
        'quotation_number' => 'QT-GCTC-' . uniqid(),
        'source_booking_id' => $vehicle1->id,
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => $vehicle1->pickup_address,
        'dropoff_address' => $vehicle1->dropoff_address,
        'distance_km' => $vehicle1->distance_km,
        'estimated_price' => 15000,
        'additional_fee' => 2000,
        'discount' => 440,
        'status' => 'accepted',
        'is_current' => true,
        'extra_vehicles' => $extraVehicles,
    ]);

    foreach ([$vehicle1, $vehicle2, $vehicle3] as $member) {
        $member->update(['quotation_id' => $quotation->id]);
    }

    return compact('teamLeader', 'customer', 'truckType', 'vehicle1', 'vehicle2', 'vehicle3', 'quotation');
}

function gctcCompleteUrl(Booking $booking): string
{
    return '/api/v1/team-leader/task/' . $booking->booking_code . '/complete';
}

it('1+8: an untouched 3-vehicle group produces the same accepted total as before (₱15,000)', function () {
    ['teamLeader' => $teamLeader, 'vehicle1' => $vehicle1] = gctcScenario();
    Sanctum::actingAs($teamLeader, ['*']);

    $this->post(gctcCompleteUrl($vehicle1), [
        'payment_method' => 'cash',
        'cash_received' => 20000,
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertOk()->assertJsonPath('success', true);

    $invoice = Invoice::where('is_current', true)->first();
    expect((float) $invoice->total)->toBe(15000.0);
});

it('2+3+4: a legitimate +₱500 correction to Vehicle 2 alone raises the consolidated invoice to ₱15,500', function () {
    ['teamLeader' => $teamLeader, 'vehicle1' => $vehicle1, 'vehicle2' => $vehicle2] = gctcScenario();

    // Legitimate post-acceptance per-vehicle correction, through the
    // existing authoritative mechanism — same as production dispatcher use.
    test()->actingAs(gctcDispatcher())->patchJson(
        route('admin.active-bookings.update-pricing', $vehicle2),
        ['additional_fee' => 500]
    )->assertOk();

    expect((float) $vehicle2->fresh()->final_total)->toBe(4980.0);

    Sanctum::actingAs($teamLeader, ['*']);
    $this->post(gctcCompleteUrl($vehicle1), [
        'payment_method' => 'cash',
        'cash_received' => 20000,
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertOk()->assertJsonPath('success', true);

    $invoice = Invoice::where('is_current', true)->first();
    expect((float) $invoice->total)->toBe(15500.0);
});

it('5+6: quotation-level additional_fee and discount are each counted exactly once alongside the correction', function () {
    ['teamLeader' => $teamLeader, 'vehicle1' => $vehicle1, 'vehicle2' => $vehicle2, 'quotation' => $quotation] = gctcScenario();

    $vehicle2->update(['additional_fee' => 500, 'vat_exclusive_total' => 4000, 'vat_amount' => 480, 'final_total' => 4980]);

    Sanctum::actingAs($teamLeader, ['*']);
    $this->post(gctcCompleteUrl($vehicle1), [
        'payment_method' => 'cash',
        'cash_received' => 20000,
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertOk();

    // member_total (4480 + 4980 + 4480 = 13940) + additional_fee (2000) - discount (440) = 15500.
    // If additional_fee or discount were dropped or doubled, this would not equal 15500.
    $invoice = Invoice::where('is_current', true)->first();
    expect((float) $invoice->total)->toBe(15500.0)
        ->and((float) $quotation->fresh()->additional_fee)->toBe(2000.0)
        ->and((float) $quotation->fresh()->discount)->toBe(440.0);
});

it('7: a cancelled group member is excluded from the recomputed total', function () {
    ['teamLeader' => $teamLeader, 'vehicle1' => $vehicle1] = gctcScenario(withCancelledSibling: true);
    Sanctum::actingAs($teamLeader, ['*']);

    $this->post(gctcCompleteUrl($vehicle1), [
        'payment_method' => 'cash',
        'cash_received' => 20000,
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertOk()->assertJsonPath('success', true);

    // The cancelled sibling's 9999 final_total must NOT be included.
    $invoice = Invoice::where('is_current', true)->first();
    expect((float) $invoice->total)->toBe(15000.0);
});

it('does not mutate the accepted Quotation row when recomputing the group total', function () {
    ['teamLeader' => $teamLeader, 'vehicle1' => $vehicle1, 'vehicle2' => $vehicle2, 'quotation' => $quotation] = gctcScenario();

    $vehicle2->update(['additional_fee' => 500, 'vat_exclusive_total' => 4000, 'vat_amount' => 480, 'final_total' => 4980]);
    $quotation = $quotation->fresh();
    $originalVersion = $quotation->version;
    $originalEstimatedPrice = (float) $quotation->estimated_price;

    Sanctum::actingAs($teamLeader, ['*']);
    $this->post(gctcCompleteUrl($vehicle1), [
        'payment_method' => 'cash',
        'cash_received' => 20000,
        'signature' => UploadedFile::fake()->image('sig.jpg'),
    ])->assertOk();

    $fresh = $quotation->fresh();
    expect($fresh->version)->toBe($originalVersion)
        ->and((float) $fresh->estimated_price)->toBe($originalEstimatedPrice)
        ->and($fresh->status)->toBe('accepted');
});
