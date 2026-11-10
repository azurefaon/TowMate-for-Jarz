<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Finance\CommissionLine;
use App\Models\Finance\CommissionPolicy;
use App\Models\Finance\CommissionStatement;
use App\Models\Finance\JobPersonnel;
use App\Models\Finance\RevenueAllocation;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use App\Services\Finance\CommissionPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Shared fixtures for the Owner Finance tests. Function names are prefixed "fin" because Pest helpers are global.

if (! function_exists('finUser')) {
    function finUser(int $roleId, array $overrides = []): User
    {
        $names = [1 => 'Owner', 2 => 'Admin', 3 => 'Team Leader', 4 => 'Driver', 5 => 'Customer', 6 => 'System Admin'];
        if (! Role::find($roleId)) {
            $role = new Role(['name' => $names[$roleId] ?? "Role {$roleId}"]);
            $role->id = $roleId;
            $role->save();
        }

        return User::factory()->create(array_merge(['role_id' => $roleId, 'status' => 'active', 'must_change_password' => false], $overrides));
    }

    function finOwner(): User
    {
        return finUser(1);
    }

    function finUnit(?User $teamLeader = null): Unit
    {
        $truckType = TruckType::create(['name' => 'FIN Truck ' . Str::random(6), 'base_rate' => 1500, 'per_km_rate' => 60]);

        return Unit::create([
            'name' => 'FIN Unit ' . Str::random(4),
            'plate_number' => 'FIN-' . random_int(1000, 9999) . Str::random(2),
            'truck_type_id' => $truckType->id,
            'status' => 'available',
            'team_leader_id' => $teamLeader?->id,
        ]);
    }

    function finBooking(Unit $unit, string $total = '1000.00'): Booking
    {
        $customer = Customer::create([
            'full_name' => 'FIN Customer',
            'phone' => '0917' . random_int(1000000, 9999999),
            'email' => 'fin-' . Str::random(8) . '@example.com',
        ]);

        return Booking::create([
            'customer_id' => $customer->id,
            'truck_type_id' => $unit->truck_type_id,
            'assigned_unit_id' => $unit->id,
            'pickup_address' => 'A',
            'dropoff_address' => 'B',
            'distance_km' => 5,
            'base_rate' => 1500,
            'per_km_rate' => 60,
            'computed_total' => $total,
            'final_total' => $total,
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    function finPersonnel(string $role = 'crew'): Personnel
    {
        return Personnel::create(['first_name' => 'Fin', 'last_name' => ucfirst($role) . Str::random(3), 'role' => $role, 'personnel_status' => 'active']);
    }

    function finAllocation(Booking $booking, Unit $unit, string $gross = '1000.00'): RevenueAllocation
    {
        return RevenueAllocation::create([
            'booking_id' => $booking->id,
            'unit_id' => $unit->id,
            'gross_amount' => $gross,
            'vat_amount' => '0.00',
            'net_amount' => $gross,
            'allocation_method' => 'single_booking',
            'collected_at' => now(),
        ]);
    }

    function finJobPersonnel(Booking $booking, Unit $unit, string $slot, ?Personnel $person = null, ?User $user = null): JobPersonnel
    {
        return JobPersonnel::create([
            'booking_id' => $booking->id,
            'unit_id' => $unit->id,
            'slot' => $slot,
            'personnel_id' => $person?->id,
            'user_id' => $user?->id,
            'name_snapshot' => $person?->full_name ?? $user?->name ?? 'Unknown',
            'source' => 'completion_capture',
            'captured_at' => now(),
        ]);
    }

    /**
     * TEST FIXTURE ONLY: an active policy whose interpretation is confirmed, standing in for the future client decision.
     * By default it confirms the Rider, substitute and combined-role answers but NOT the shared-role rule.
     */
    function finActivePolicy(User $owner, ?array $rules = null): CommissionPolicy
    {
        $service = app(CommissionPolicyService::class);
        $policy = CommissionPolicy::where('version', 1)->firstOrFail();
        $rules ??= ['rider_role' => 'confirmed', 'substitutes' => 'paid_in_recorded_role', 'combined_roles' => 'paid_per_role'];
        $service->confirmInterpretation($owner, $policy, 'test_base', 'test_rule', $rules);

        return $service->activate($owner, $policy->fresh(), now()->toDateString());
    }

    /** Last week's Monday (Manila), so fixtures are never dated in the future. */
    function finLastWeek(): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::now(config('finance.timezone'))->startOfWeek(\Carbon\CarbonImmutable::MONDAY)->subWeek();
    }

    /** A finished job serviced last Wednesday whose full charge was verified (cash, Dispatcher-confirmed). */
    function finServicedBooking(Unit $unit, string $total = '1000.00', array $overrides = []): Booking
    {
        $customer = Customer::create(['full_name' => 'FIN Client', 'phone' => '0917' . random_int(1000000, 9999999), 'email' => 'fs-' . Str::random(8) . '@example.com']);
        $at = finLastWeek()->addDays(2)->setTime(10, 0);

        return Booking::create(array_merge([
            'customer_id' => $customer->id, 'truck_type_id' => $unit->truck_type_id, 'assigned_unit_id' => $unit->id,
            'pickup_address' => 'Pickup ' . Str::random(5), 'dropoff_address' => 'Quezon City', 'distance_km' => 30,
            'base_rate' => 1500, 'per_km_rate' => 60, 'computed_total' => $total, 'final_total' => $total,
            'completion_requested_at' => $at, 'payment_submitted_at' => $at, 'payment_method' => 'cash', 'cash_received' => $total,
            'status' => 'completed', 'completed_at' => $at->addHour(),
        ], $overrides))->fresh();
    }

    /** Records a full crew through the Owner crew service: TL account, driver, rider and crew mate. */
    function finRecordCrew(User $owner, Booking $booking, Unit $unit, ?User $teamLeader = null, bool $withRider = true): array
    {
        $crew = app(\App\Services\Finance\JobCrewService::class);
        $teamLeader ??= finUser(3);
        $driver = finPersonnel('driver');
        $rider = finPersonnel('driver');
        $mate = finPersonnel('crew');

        $crew->record($owner, $booking, ['unit_id' => $unit->id, 'participant' => "user:{$teamLeader->id}", 'commission_role' => 'team_leader']);
        $crew->record($owner, $booking, ['unit_id' => $unit->id, 'participant' => "personnel:{$driver->id}", 'commission_role' => 'driver']);
        if ($withRider) {
            $crew->record($owner, $booking, ['unit_id' => $unit->id, 'participant' => "personnel:{$rider->id}", 'commission_role' => 'rider']);
        }
        $crew->record($owner, $booking, ['unit_id' => $unit->id, 'participant' => "personnel:{$mate->id}", 'commission_role' => 'crew_mate']);

        return ['teamLeader' => $teamLeader, 'driver' => $driver, 'rider' => $rider, 'crew' => $mate];
    }

    /**
     * A statement For Review: one verified 1,000.00 job with a full recorded crew (60/12/10/10/8 → 600/120/100/100/80).
     * With $confirmPolicy the TEST-ONLY confirmed policy is activated so the statement is approvable.
     *
     * @return array{statement: CommissionStatement, driver: Personnel, teamLeader: User, rider: Personnel, crew: Personnel, unit: Unit, booking: Booking}
     */
    function finReviewStatement(User $owner, bool $confirmPolicy = true, string $total = '1000.00'): array
    {
        if ($confirmPolicy && ! CommissionPolicy::where('status', 'active')->exists()) {
            finActivePolicy($owner);
        }
        $teamLeader = finUser(3);
        $unit = finUnit($teamLeader);
        $booking = finServicedBooking($unit, $total);
        $people = finRecordCrew($owner, $booking, $unit, $teamLeader);

        $service = app(\App\Services\Finance\CommissionStatementService::class);
        $statement = $service->submitForReview($owner, $service->generate($owner, finLastWeek()->toDateString(), $unit->id));

        return ['statement' => $statement->fresh(), 'unit' => $unit, 'booking' => $booking] + $people;
    }

    /** The Monday of the current week as Y-m-d. */
    function finMonday(int $weeksAgo = 0): string
    {
        return now()->startOfWeek(\Carbon\CarbonInterface::MONDAY)->subWeeks($weeksAgo)->toDateString();
    }

    /**
     * A draft statement with one 1,000.00 job split 60/12/10/10/8.
     *
     * @return array{statement: CommissionStatement, driver: Personnel, teamLeader: User, rider: Personnel, crew: Personnel}
     */
    function finDraftStatement(CommissionPolicy $policy, ?string $weekStart = null): array
    {
        $teamLeader = finUser(3);
        $unit = finUnit($teamLeader);
        $booking = finBooking($unit);
        $allocation = finAllocation($booking, $unit);
        $driver = finPersonnel('driver');
        $rider = finPersonnel('driver');
        $crew = finPersonnel('crew');

        $statement = CommissionStatement::create([
            'period_start' => $weekStart ?? finMonday(),
            'period_end' => \Carbon\Carbon::parse($weekStart ?? finMonday())->addDays(5)->toDateString(),
            'policy_id' => $policy->id,
            'total_base' => '1000.00',
            'company_amount' => '600.00',
            'personnel_amount' => '400.00',
            'line_count' => 5,
        ]);

        $lines = [
            ['company', null, null, 'company', 'Company', '60', '600.00'],
            ['driver', finJobPersonnel($booking, $unit, 'driver_1', $driver), $driver, "personnel:{$driver->id}", $driver->full_name, '12', '120.00'],
            ['team_leader', finJobPersonnel($booking, $unit, 'team_leader', null, $teamLeader), null, "user:{$teamLeader->id}", $teamLeader->name, '10', '100.00'],
            ['rider', finJobPersonnel($booking, $unit, 'driver_2', $rider), $rider, "personnel:{$rider->id}", $rider->full_name, '10', '100.00'],
            ['crew_mate', finJobPersonnel($booking, $unit, 'crew_member_1', $crew), $crew, "personnel:{$crew->id}", $crew->full_name, '8', '80.00'],
        ];

        foreach ($lines as [$role, $jobPersonnel, $person, $key, $name, $rate, $amount]) {
            CommissionLine::create([
                'statement_id' => $statement->id,
                'booking_id' => $booking->id,
                'revenue_allocation_id' => $allocation->id,
                'job_personnel_id' => $jobPersonnel?->id,
                'commission_role' => $role,
                'recipient_personnel_id' => $person?->id,
                'recipient_user_id' => $role === 'team_leader' ? $teamLeader->id : null,
                'recipient_key' => $key,
                'recipient_name' => $name,
                'base_amount' => '1000.00',
                'rate' => $rate,
                'amount' => $amount,
            ]);
        }

        return compact('statement', 'driver', 'teamLeader', 'rider', 'crew');
    }

    /** Runs $fn inside a savepoint and asserts PostgreSQL rejects it, keeping the outer test transaction usable. */
    function finExpectDbRejects(Closure $fn, string $messageFragment = ''): void
    {
        try {
            DB::transaction($fn);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($messageFragment !== '') {
                expect($e->getMessage())->toContain($messageFragment);
            }
            expect(true)->toBeTrue();

            return;
        }

        throw new \PHPUnit\Framework\AssertionFailedError('Expected the database to reject the statement, but it succeeded.');
    }
}
