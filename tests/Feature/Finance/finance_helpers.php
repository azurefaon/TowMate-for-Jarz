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

    /** An active policy whose interpretation is confirmed (stands in for the future client decision). */
    function finActivePolicy(User $owner): CommissionPolicy
    {
        $service = app(CommissionPolicyService::class);
        $policy = CommissionPolicy::where('version', 1)->firstOrFail();
        $service->confirmInterpretation($owner, $policy, 'test_base', 'test_rule');

        return $service->activate($owner, $policy->fresh(), now()->toDateString());
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
