<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\QuotationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

function givrRole(int $id, string $name): Role
{
    return Role::find($id) ?: tap(new Role(['name' => $name]), function ($r) use ($id) {
        $r->id = $id;
        $r->save();
    });
}

function givrDispatcher(): User
{
    return User::factory()->create(['role_id' => givrRole(2, 'Dispatcher')->id, 'status' => 'active']);
}

function givrTruckType(float $baseRate, float $perKmRate, string $name): TruckType
{
    return TruckType::create([
        'name' => $name,
        'base_rate' => $baseRate,
        'per_km_rate' => $perKmRate,
        'status' => 'active',
    ]);
}

function givrVehicleType(int $requiredTruckTypeId, string $name): VehicleType
{
    return VehicleType::create([
        'name' => $name,
        'category' => '4_wheeler',
        'required_truck_type_id' => $requiredTruckTypeId,
        'status' => 'active',
    ]);
}

function givrReadyUnit(TruckType $truckType, string $label): array
{
    $leader = User::factory()->create(['role_id' => givrRole(3, 'Team Leader')->id]);
    Cache::put("teamleader:presence:{$leader->id}", now()->timestamp, now()->addMinutes(2));
    $leader->forceFill(['must_change_password' => false])->save();

    $unit = Unit::create([
        'name' => $label,
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $leader->id,
        'status' => 'available',
        'driver_name' => 'GIVR Driver',
    ]);

    return [$unit, $leader];
}

function givrImages(int $count): array
{
    return array_map(fn ($i) => UploadedFile::fake()->image("givr-vehicle-$i.jpg", 300, 300), range(1, $count));
}

function givrProgressToArrivedDropoff(Booking $booking): void
{
    test()->postJson('/api/v1/team-leader/task/' . $booking->booking_code . '/accept')->assertOk();
    foreach (['on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff'] as $status) {
        $payload = ['status' => $status];
        if ($status === 'arrived_pickup') {
            $payload['lat'] = (float) $booking->pickup_lat;
            $payload['lng'] = (float) $booking->pickup_lng;
        }
        if ($status === 'arrived_dropoff') {
            $payload['lat'] = (float) $booking->dropoff_lat;
            $payload['lng'] = (float) $booking->dropoff_lng;
        }
        test()->patchJson('/api/v1/team-leader/task/' . $booking->booking_code . '/status', $payload)->assertOk();
    }
}

it('derives the group invoice subtotal from each vehicle own taxable subtotal using the Owner-configured VAT rate, not a hardcoded 12 percent', function () {
    SystemSetting::setValue('vat_rate_percentage', 15);

    $dispatcher = givrDispatcher();
    $user = User::factory()->create(['role_id' => givrRole(5, 'Customer')->id, 'status' => 'active']);
    $customer = Customer::create([
        'user_id' => $user->id,
        'full_name' => $user->name,
        'phone' => '0917' . fake()->unique()->numerify('#######'),
        'email' => $user->email,
    ]);

    $truckLight = givrTruckType(1500, 60, 'GIVR Light Duty');
    [$unitLight, $leaderLight] = givrReadyUnit($truckLight, 'GIVR Light Unit');
    $vehicleSedan = givrVehicleType($truckLight->id, 'GIVR Sedan');

    $truckMedium = givrTruckType(2500, 100, 'GIVR Medium Duty');
    [$unitMedium, $leaderMedium] = givrReadyUnit($truckMedium, 'GIVR Medium Unit');
    $vehicleVan = givrVehicleType($truckMedium->id, 'GIVR Van');

    Sanctum::actingAs($user, ['*']);

    $response = test()->postJson('/api/v1/bookings', [
        'vehicle_type_id' => $vehicleSedan->id,
        'pickup_address' => 'Origin',
        'pickup_lat' => 14.5995,
        'pickup_lng' => 120.9842,
        'dropoff_address' => 'Destination',
        'dropoff_lat' => 14.6905,
        'dropoff_lng' => 120.9842,
        'distance_km' => 12,
        'service_type' => 'book_now',
        'vehicle_images' => givrImages(1),
        'extra_vehicles' => json_encode([
            ['vehicle_type_id' => $vehicleVan->id],
        ]),
        'extra_vehicle_images' => [
            0 => givrImages(1),
        ],
    ]);
    $response->assertCreated();
    $groupCode = $response->json('group_code');

    $primary = Booking::where('group_code', $groupCode)->where('vehicle_type_id', $vehicleSedan->id)->firstOrFail();
    $sibling = Booking::where('group_code', $groupCode)->where('vehicle_type_id', $vehicleVan->id)->firstOrFail();

    $quotation = Quotation::find($primary->quotation_id);
    expect($quotation)->not->toBeNull();

    $quotationService = app(QuotationService::class);
    $quotationService->sendQuotation($quotation, 168);
    $quotationService->acceptQuotation($quotation->fresh());

    $primary->refresh();
    $sibling->refresh();

    expect((float) $primary->vat_rate)->toBe(0.15);
    expect((float) $sibling->vat_rate)->toBe(0.15);

    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $primary), [
        'action' => 'accept', 'assigned_unit_id' => $unitLight->id,
    ])->assertOk();
    test()->actingAs($dispatcher)->postJson(route('admin.booking.assign', $sibling), [
        'action' => 'accept', 'assigned_unit_id' => $unitMedium->id,
    ])->assertOk();

    $primary->update(['payment_proof_path' => 'task-photos/givr-proof-a.jpg']);
    $sibling->update(['payment_proof_path' => 'task-photos/givr-proof-b.jpg']);

    Sanctum::actingAs($leaderLight, ['*']);
    givrProgressToArrivedDropoff($primary);
    Sanctum::actingAs($leaderMedium, ['*']);
    givrProgressToArrivedDropoff($sibling);

    $groupTotal = round((float) $primary->fresh()->final_total + (float) $sibling->fresh()->final_total, 2);
    $expectedSubtotal = round((float) $primary->fresh()->vat_exclusive_total + (float) $sibling->fresh()->vat_exclusive_total, 2);
    $wrongLegacySubtotal = round($groupTotal / 1.12, 2);

    Sanctum::actingAs($leaderLight, ['*']);
    $signature = UploadedFile::fake()->image('sig.png');
    $complete = test()->post('/api/v1/team-leader/task/' . $primary->booking_code . '/complete', [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => (string) $groupTotal,
    ]);
    $complete->assertOk()->assertJsonPath('success', true);

    $invoice = Invoice::where('quotation_id', $quotation->id)->where('is_current', true)->first();
    expect($invoice)->not->toBeNull();
    expect((float) $invoice->total)->toBe($groupTotal);
    expect((float) $invoice->subtotal)->toBe($expectedSubtotal);
    expect((float) $invoice->subtotal)->not->toBe($wrongLegacySubtotal);
});
