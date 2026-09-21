<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

function pclRole(): Role
{
    $role = Role::find(3);
    if (! $role) {
        $role = new Role(['name' => 'Team Leader', 'description' => 'Tow unit team leader']);
        $role->id = 3;
        $role->save();
    }
    return $role;
}

function pclDispatcherRole(): Role
{
    $role = Role::find(2);
    if (! $role) {
        $role = new Role(['name' => 'Admin', 'description' => 'Dispatcher']);
        $role->id = 2;
        $role->save();
    }
    return $role;
}

function pclTeamLeaderAndUnit(string $email): array
{
    pclRole();

    $teamLeader = User::factory()->create([
        'role_id' => 3,
        'must_change_password' => false,
        'name' => 'PCL Test Leader',
        'email' => $email,
    ]);

    $truckType = TruckType::create([
        'name' => 'PCL Truck ' . uniqid(),
        'base_rate' => 1500,
        'per_km_rate' => 300,
    ]);

    $unit = Unit::create([
        'name' => "{$teamLeader->name}'s Unit",
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
    ]);

    return [$teamLeader, $unit, $truckType];
}

function pclDispatcher(): User
{
    pclDispatcherRole();
    return User::factory()->create(['role_id' => 2, 'must_change_password' => false]);
}

function pclCoords(): array
{
    return [
        'pickup_lat' => 14.7054035,
        'pickup_lng' => 121.0463671,
        'dropoff_lat' => 14.7865449,
        'dropoff_lng' => 121.0749723,
    ];
}

function pclGroupedBooking(User $teamLeader, Unit $unit, TruckType $truckType, string $groupCode): Booking
{
    $customer = Customer::create([
        'full_name' => 'PCL Customer',
        'phone' => '09171234567',
        'email' => 'pcl-' . uniqid() . '@example.test',
    ]);

    return Booking::create(array_merge([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'assigned_team_leader_id' => $teamLeader->id,
        'group_code' => $groupCode,
        'pickup_address' => 'Pasay',
        'dropoff_address' => 'Makati',
        'distance_km' => 9.53,
        'base_rate' => $truckType->base_rate,
        'per_km_rate' => $truckType->per_km_rate,
        'final_total' => 4658.08,
        'status' => 'arrived_dropoff',
        'service_type' => 'book_now',
        'assigned_at' => now(),
    ], pclCoords()));
}

function pclCompleteUrl(Booking $booking): string
{
    return '/api/v1/team-leader/task/' . $booking->booking_code . '/complete';
}

it('does not return the team leader to Arrived at Drop-off after the dispatcher confirms a grouped payment', function () {
    [$teamLeader, $unit, $truckType] = pclTeamLeaderAndUnit('pcl-leader-clean@example.test');
    $dispatcher = pclDispatcher();
    $groupCode = 'PCL-GRP-' . uniqid();

    $bookingA = pclGroupedBooking($teamLeader, $unit, $truckType, $groupCode);
    $bookingB = pclGroupedBooking($teamLeader, $unit, $truckType, $groupCode);
    $bookingB->update(['pickup_address' => $bookingA->pickup_address, 'dropoff_address' => $bookingA->dropoff_address]);

    $quotation = Quotation::create([
        'quotation_number' => 'Q-PCL-' . uniqid(),
        'source_booking_id' => $bookingA->id,
        'customer_id' => $bookingA->customer_id,
        'truck_type_id' => $truckType->id,
        'pickup_address' => $bookingA->pickup_address,
        'dropoff_address' => $bookingA->dropoff_address,
        'distance_km' => 9.53,
        'estimated_price' => 9316.16,
        'status' => 'accepted',
        'extra_vehicles' => [
            ['booking_id' => $bookingA->id, 'truck_type_name' => $truckType->name, 'final_total' => 4658.08],
            ['booking_id' => $bookingB->id, 'truck_type_name' => $truckType->name, 'final_total' => 4658.08],
        ],
    ]);
    $bookingA->update(['quotation_id' => $quotation->id]);
    $bookingB->update(['quotation_id' => $quotation->id]);

    Sanctum::actingAs($teamLeader, ['*']);
    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    $bookingB->update(['payment_proof_path' => 'task-photos/pcl-proof.jpg']);
    $complete = test()->post(pclCompleteUrl($bookingB), [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => '9316.16',
    ]);
    $complete->assertOk()->assertJsonPath('success', true);

    expect($bookingA->fresh()->status)->toBe('waiting_verification');
    expect($bookingB->fresh()->status)->toBe('waiting_verification');

    $afterSubmit = test()->getJson('/api/v1/team-leader/task');
    $afterSubmit->assertOk();
    expect($afterSubmit->json('data.status'))->toBe('waiting_verification');

    test()->actingAs($dispatcher)
        ->postJson(route('admin.jobs.confirm-payment', $bookingA))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($bookingA->fresh()->status)->toBe('completed');
    expect($bookingB->fresh()->status)->toBe('completed');

    Sanctum::actingAs($teamLeader, ['*']);
    $afterConfirm = test()->getJson('/api/v1/team-leader/task');
    $afterConfirm->assertOk();

    $data = $afterConfirm->json('data');
    if ($data !== null) {
        expect($data['status'])->not->toBe('arrived_dropoff');
        expect($data['status'])->toBe('completed');
    }
});

it('reproduces and fixes the exact reported contamination: a stale solo demo task must not resurface as the current task after the group is confirmed', function () {
    [$teamLeader] = pclTeamLeaderAndUnit('pcl-leader-contaminated@example.test');

    Artisan::call('dev:seed-tl-demo-task', ['--team-leader-email' => $teamLeader->email]);
    $staleBooking = Booking::where('assigned_team_leader_id', $teamLeader->id)
        ->where('dispatcher_note', 'like', 'DEMO%')
        ->firstOrFail();
    $staleBooking->update(['status' => 'arrived_dropoff']);

    Artisan::call('dev:seed-tl-demo-group-task', [
        '--team-leader-email' => $teamLeader->email,
        '--vehicles' => 2,
    ]);

    expect(Booking::find($staleBooking->id))->toBeNull();

    $groupBookings = Booking::where('assigned_team_leader_id', $teamLeader->id)
        ->whereNotNull('group_code')
        ->orderBy('id')
        ->get();
    expect($groupBookings)->toHaveCount(2);
    foreach ($groupBookings as $member) {
        $member->update(['status' => 'arrived_dropoff']);
    }

    Sanctum::actingAs($teamLeader, ['*']);
    $primary = $groupBookings->first();
    $signature = \Illuminate\Http\UploadedFile::fake()->image('sig.png');
    $primary->update(['payment_proof_path' => 'task-photos/pcl-contam-proof.jpg']);
    $groupTotal = (float) Quotation::find($primary->quotation_id)->estimated_price;
    $complete = test()->post(pclCompleteUrl($primary), [
        'signature' => $signature,
        'payment_method' => 'cash',
        'cash_received' => (string) $groupTotal,
    ]);
    $complete->assertOk()->assertJsonPath('success', true);

    $dispatcher = pclDispatcher();
    test()->actingAs($dispatcher)
        ->postJson(route('admin.jobs.confirm-payment', $primary))
        ->assertOk()
        ->assertJsonPath('success', true);

    foreach ($groupBookings as $member) {
        expect($member->fresh()->status)->toBe('completed');
    }

    Sanctum::actingAs($teamLeader, ['*']);
    $afterConfirm = test()->getJson('/api/v1/team-leader/task');
    $afterConfirm->assertOk();

    $data = $afterConfirm->json('data');
    expect($data)->not->toBeNull();
    expect($data['status'])->not->toBe('arrived_dropoff');
    expect($data['status'])->toBe('completed');
});
