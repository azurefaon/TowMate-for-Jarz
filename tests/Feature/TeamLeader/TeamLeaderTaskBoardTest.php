<?php

use App\Mail\BookingReceiptMail;
use App\Mail\TaskCompletionVerificationMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

function makeTeamLeaderScenario(): array
{
    $teamLeaderRole = Role::find(3);

    if (! $teamLeaderRole) {
        $teamLeaderRole = new Role([
            'name' => 'Team Leader',
            'description' => 'Tow unit team leader',
        ]);
        $teamLeaderRole->id = 3;
        $teamLeaderRole->save();
    }

    $teamLeader = User::factory()->create([
        'role_id' => $teamLeaderRole->id,
    ]);

    $truckType = TruckType::create([
        'name' => 'Recovery Truck ' . fake()->unique()->word(),
        'base_rate' => 1800,
        'per_km_rate' => 85,
        'max_tonnage' => 6,
        'description' => 'Heavy towing support',
    ]);

    $unit = Unit::create([
        'name' => 'Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $truckType->id,
        'team_leader_id' => $teamLeader->id,
        'status' => 'available',
    ]);

    $customer = Customer::create([
        'full_name' => 'Maria Santos',
        'age' => 32,
        'phone' => '09171234567',
        'email' => 'maria@example.com',
    ]);

    $booking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'created_by_admin_id' => $teamLeader->id,
        'age' => 32,
        'pickup_address' => 'Ortigas Center',
        'dropoff_address' => 'Pasig City Hall',
        'distance_km' => 8.5,
        'base_rate' => 1800,
        'per_km_rate' => 85,
        'computed_total' => 2522.50,
        'final_total' => 2522.50,
        'quotation_generated' => true,
        'quotation_number' => 'Q-TEST-1001',
        'status' => 'assigned',
        'assigned_at' => now(),
    ]);

    $secondBooking = Booking::create([
        'customer_id' => $customer->id,
        'truck_type_id' => $truckType->id,
        'assigned_unit_id' => $unit->id,
        'created_by_admin_id' => $teamLeader->id,
        'age' => 32,
        'pickup_address' => 'Shaw Boulevard',
        'dropoff_address' => 'Mandaluyong City Hall',
        'distance_km' => 6.25,
        'base_rate' => 1800,
        'per_km_rate' => 85,
        'computed_total' => 2331.25,
        'final_total' => 2331.25,
        'quotation_generated' => true,
        'quotation_number' => 'Q-TEST-1002',
        'status' => 'assigned',
        'assigned_at' => now(),
    ]);

    return [$teamLeader, $booking->fresh(['customer', 'truckType', 'unit']), $secondBooking->fresh(['customer', 'truckType', 'unit'])];
}

it('lets a team leader accept one task and locks the rest of the queue', function () {
    [$teamLeader, $booking, $secondBooking] = makeTeamLeaderScenario();

    $response = $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.accept', $booking));

    $response->assertOk()->assertJsonPath('success', true)
        ->assertJsonPath('task.id', $booking->job_code)
        ->assertJsonPath('redirect_url', route('teamleader.task.show', $booking));

    $booking->refresh();
    $secondBooking->refresh();

    expect($booking->assigned_team_leader_id)->toBe($teamLeader->id)
        ->and($secondBooking->assigned_team_leader_id)->toBeNull();

    $this->actingAs($teamLeader)
        ->get(route('teamleader.tasks'))
        ->assertOk()
        ->assertSee($booking->job_code, false)
        ->assertDontSee($secondBooking->job_code, false);

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.accept', $secondBooking))
        ->assertStatus(422)
        ->assertJsonPath('active_task_id', $booking->job_code);
});

it('runs the focused task flow from travel to completion and customer confirmation', function () {
    Mail::fake();

    [$teamLeader, $booking] = makeTeamLeaderScenario();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.accept', $booking))
        ->assertOk();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.driver', $booking), [
            'driver_name' => 'Juan Dela Cruz',
        ])
        ->assertOk()
        ->assertJsonPath('task.driver_name', 'Juan Dela Cruz');

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.proceed', $booking))
        ->assertOk()
        ->assertJsonPath('status', 'on_the_way');

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.start', $booking))
        ->assertOk()
        ->assertJsonPath('status', 'in_progress');

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.complete', $booking), [
            'completion_note' => 'Vehicle delivered safely.',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'waiting_verification');

    $booking->refresh();

    expect($booking->driver_name)->toBe('Juan Dela Cruz')
        ->and($booking->status)->toBe('waiting_verification')
        ->and($booking->customer_verification_status)->toBe('pending');

    Mail::assertSent(TaskCompletionVerificationMail::class);

    $approveUrl = URL::temporarySignedRoute(
        'teamleader.verification.respond',
        now()->addMinutes(5),
        ['booking' => $booking, 'decision' => 'approve']
    );

    $this->get($approveUrl)->assertOk();

    $completedBooking = $booking->fresh('receipt');

    expect($completedBooking->status)->toBe('completed')
        ->and($completedBooking->customer_verification_status)->toBe('approved')
        ->and($completedBooking->receipt)->not->toBeNull();

    Mail::assertSent(BookingReceiptMail::class, fn(BookingReceiptMail $mail) => $mail->hasTo($booking->customer->email));
});

it('allows a team leader to change the saved driver before leaving the assigned stage', function () {
    [$teamLeader, $booking] = makeTeamLeaderScenario();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.accept', $booking))
        ->assertOk();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.driver', $booking), [
            'driver_name' => 'Juan Dela Cruz',
        ])
        ->assertOk()
        ->assertJsonPath('task.driver_name', 'Juan Dela Cruz');

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.driver', $booking), [
            'driver_name' => 'Pedro Reyes',
        ])
        ->assertOk()
        ->assertJsonPath('task.driver_name', 'Pedro Reyes');

    expect($booking->fresh()->driver_name)->toBe('Pedro Reyes');
});

it('allows a team leader to return an accepted task back to the queue with a reason', function () {
    [$teamLeader, $booking] = makeTeamLeaderScenario();

    $unit = $teamLeader->unit()->firstOrFail();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.accept', $booking))
        ->assertOk();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.return', $booking), [
            'return_reason' => 'Customer is unreachable at the pickup area.',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'assigned')
        ->assertJsonPath('return_reason', 'Customer is unreachable at the pickup area.');

    $booking->refresh();

    expect($booking->assigned_team_leader_id)->toBeNull()
        ->and($booking->assigned_unit_id)->toBe($unit->id)
        ->and($booking->status)->toBe('assigned')
        ->and($booking->return_reason)->toBe('Customer is unreachable at the pickup area.')
        ->and($booking->returned_by_team_leader_id)->toBe($teamLeader->id)
        ->and($booking->returned_at)->not->toBeNull()
        ->and($unit->fresh()->team_leader_id)->toBe($teamLeader->id);

    $this->actingAs($teamLeader)
        ->get(route('teamleader.tasks'))
        ->assertOk()
        ->assertSee($booking->job_code, false)
        ->assertSee('Accept Task');

    $this->actingAs($teamLeader)
        ->getJson(route('teamleader.tasks'))
        ->assertOk()
        ->assertJsonFragment([
            'booking_code' => $booking->fresh()->job_code,
            'status' => 'assigned',
        ]);
});

it('requires a reason before a team leader can return a task', function () {
    [$teamLeader, $booking] = makeTeamLeaderScenario();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.accept', $booking))
        ->assertOk();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.return', $booking), [
            'return_reason' => '',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['return_reason']);
});

it('does not allow a team leader to return a task after the job has started', function () {
    [$teamLeader, $booking] = makeTeamLeaderScenario();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.accept', $booking))
        ->assertOk();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.driver', $booking), [
            'driver_name' => 'Juan Dela Cruz',
        ])
        ->assertOk();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.proceed', $booking))
        ->assertOk()
        ->assertJsonPath('status', 'on_the_way');

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.start', $booking))
        ->assertOk()
        ->assertJsonPath('status', 'in_progress');

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.return', $booking), [
            'return_reason' => 'Need to return after starting.',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This task can no longer be returned after the job has started.');
});

it('shows dispatcher notifications and active jobs once a team leader takes the booking', function () {
    [$teamLeader, $booking] = makeTeamLeaderScenario();

    $dispatcherRole = Role::find(2);

    if (! $dispatcherRole) {
        $dispatcherRole = new Role([
            'name' => 'Dispatcher',
            'description' => 'Dispatcher role',
        ]);
        $dispatcherRole->id = 2;
        $dispatcherRole->save();
    }

    $dispatcher = User::factory()->create([
        'role_id' => $dispatcherRole->id,
    ]);

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.accept', $booking))
        ->assertOk();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.driver', $booking), [
            'driver_name' => 'Juan Dela Cruz',
        ])
        ->assertOk();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.proceed', $booking))
        ->assertOk()
        ->assertJsonPath('status', 'on_the_way');

    $this->actingAs($dispatcher)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee($booking->job_code, false)
        ->assertSee('taken by', false);

    $this->actingAs($dispatcher)
        ->get(route('admin.jobs'))
        ->assertOk()
        ->assertSee($booking->job_code, false)
        ->assertSee('On The Way', false);
});

it('removes the team leader unit assignment when they go offline', function () {
    [$teamLeader] = makeTeamLeaderScenario();

    $unit = $teamLeader->unit()->firstOrFail();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.presence.offline'))
        ->assertOk()
        ->assertJsonPath('presence', 'offline');

    expect($unit->fresh()->team_leader_id)->toBeNull()
        ->and($unit->fresh()->status)->toBe('available');
});

it('returns an active booking to dispatch when the team leader goes offline', function () {
    [$teamLeader, $booking] = makeTeamLeaderScenario();

    $unit = $teamLeader->unit()->firstOrFail();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.accept', $booking))
        ->assertOk();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.presence.offline'))
        ->assertOk();

    expect($booking->fresh()->assigned_team_leader_id)->toBeNull()
        ->and($booking->fresh()->assigned_unit_id)->toBe($unit->id)
        ->and($booking->fresh()->status)->toBe('assigned');
});

it('keeps the dispatcher assigned unit owned by the same team leader across the full job lifecycle', function () {
    [$teamLeader, $booking] = makeTeamLeaderScenario();

    $unit = $teamLeader->unit()->firstOrFail();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.accept', $booking))
        ->assertOk();

    expect($unit->fresh()->status)->toBe('on_job')
        ->and($unit->fresh()->team_leader_id)->toBe($teamLeader->id);

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.driver', $booking), [
            'driver_name' => 'Jordan Tow',
        ])
        ->assertOk();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.proceed', $booking))
        ->assertOk();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.start', $booking))
        ->assertOk();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.complete', $booking), [
            'completion_note' => 'Service complete',
        ])
        ->assertOk();

    expect($unit->fresh()->status)->toBe('available')
        ->and($unit->fresh()->team_leader_id)->toBe($teamLeader->id);
});

it('keeps an approved booking visible after refresh even when the saved status is accepted', function () {
    [$teamLeader, $booking] = makeTeamLeaderScenario();

    $booking->update([
        'status' => 'accepted',
        'assigned_team_leader_id' => null,
        'assigned_unit_id' => $teamLeader->unit()->firstOrFail()->id,
    ]);

    $this->actingAs($teamLeader)
        ->getJson(route('teamleader.tasks'))
        ->assertOk()
        ->assertJsonFragment([
            'booking_code' => $booking->fresh()->job_code,
            'status' => 'accepted',
        ]);
});

it('does not remove the team leader unit just because the dispatcher dashboard refreshes', function () {
    [$teamLeader, $booking] = makeTeamLeaderScenario();

    $booking->update([
        'status' => 'confirmed',
        'assigned_team_leader_id' => null,
        'assigned_unit_id' => $teamLeader->unit()->firstOrFail()->id,
    ]);

    app(\App\Services\TeamLeaderAvailabilityService::class)
        ->summarize(collect([$teamLeader]));

    expect($teamLeader->unit()->firstOrFail()->team_leader_id)->toBe($teamLeader->id)
        ->and($booking->fresh()->assigned_unit_id)->toBe($teamLeader->unit()->firstOrFail()->id);
});

it('returns a safe redirect instead of a booking not found error during realtime polling', function () {
    [$teamLeader, $booking] = makeTeamLeaderScenario();

    $booking->update([
        'assigned_team_leader_id' => null,
        'status' => 'assigned',
    ]);

    $this->actingAs($teamLeader)
        ->getJson(route('teamleader.task.status', $booking))
        ->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('redirect_url', route('teamleader.tasks'));
});

it('only shows a confirmed booking to the team leader who owns the assigned unit', function () {
    [$ownerLeader, $booking] = makeTeamLeaderScenario();

    $otherLeader = User::factory()->create([
        'role_id' => 3,
    ]);

    Unit::create([
        'name' => 'Unit ' . fake()->unique()->numerify('##'),
        'plate_number' => fake()->unique()->bothify('???-####'),
        'truck_type_id' => $booking->truck_type_id,
        'team_leader_id' => $otherLeader->id,
        'status' => 'available',
    ]);

    $booking->update([
        'status' => 'confirmed',
        'assigned_team_leader_id' => null,
        'assigned_unit_id' => $ownerLeader->unit()->firstOrFail()->id,
    ]);

    $this->actingAs($ownerLeader)
        ->getJson(route('teamleader.tasks'))
        ->assertOk()
        ->assertJsonFragment([
            'booking_code' => $booking->fresh()->job_code,
        ]);

    $this->actingAs($otherLeader)
        ->getJson(route('teamleader.tasks'))
        ->assertOk()
        ->assertJsonMissing([
            'booking_code' => $booking->fresh()->job_code,
        ]);
});
