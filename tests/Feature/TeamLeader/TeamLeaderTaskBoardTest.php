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
        ->assertRedirect(route('teamleader.task.show', $booking));

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

it('allows a team leader to return an accepted task back to the queue', function () {
    [$teamLeader, $booking] = makeTeamLeaderScenario();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.accept', $booking))
        ->assertOk();

    $this->actingAs($teamLeader)
        ->postJson(route('teamleader.task.return', $booking))
        ->assertOk()
        ->assertJsonPath('status', 'assigned');

    $booking->refresh();

    expect($booking->assigned_team_leader_id)->toBeNull()
        ->and($booking->status)->toBe('assigned');

    $this->actingAs($teamLeader)
        ->get(route('teamleader.tasks'))
        ->assertOk();
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
