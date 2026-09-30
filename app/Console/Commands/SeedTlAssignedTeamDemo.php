<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

class SeedTlAssignedTeamDemo extends Command
{
    protected $signature = 'dev:seed-tl-assigned-team-demo';
    protected $description = 'Create or reset a demo Team Leader with a fully staffed Unit and an assigned task, for manually checking the Assigned Team card (local/dev only)';

    public const EMAIL = 'tl.assigned.demo@example.com';
    public const PASSWORD = 'TlDemo@2026';
    private const UNIT_PLATE = 'DEMO 0001';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Refusing to run: this command only operates in the local/testing environment.');
            return 1;
        }

        $role = Role::find(3) ?? tap(new Role(['name' => 'Team Leader', 'description' => 'Tow unit team leader']), function (Role $role) {
            $role->id = 3;
            $role->save();
        });

        $teamLeader = User::firstOrNew(['email' => self::EMAIL]);
        $teamLeader->forceFill([
            'name' => 'Demo Team Leader',
            'password' => Hash::make(self::PASSWORD),
            'role_id' => $role->id,
            'status' => 'active',
            'must_change_password' => false,
            'email_verified_at' => $teamLeader->email_verified_at ?? now(),
        ])->save();

        $truckType = TruckType::where('class', 'heavy')->where('status', 'active')->orderBy('id')->first()
            ?? TruckType::create([
                'name' => 'Heavy Duty',
                'class' => 'heavy',
                'base_rate' => 3000,
                'per_km_rate' => 500,
                'status' => 'active',
            ]);

        Unit::updateOrCreate(
            ['plate_number' => self::UNIT_PLATE],
            [
                'name' => 'Demo Unit 01',
                'truck_type_id' => $truckType->id,
                'team_leader_id' => $teamLeader->id,
                'driver_id' => null,
                'driver_name' => 'Pedro Santos',
                'crew_member_1_name' => 'Maria Reyes',
                'crew_member_2_name' => 'Jose Ramirez',
                'status' => 'available',
            ],
        );

        $customer = Customer::where('email', 'demo.tl.task@towmate.test')->first();
        $hasTask = $customer && Booking::where('customer_id', $customer->id)
            ->where('assigned_team_leader_id', $teamLeader->id)
            ->exists();

        $exit = Artisan::call('dev:seed-tl-demo-task', array_filter([
            '--team-leader-email' => self::EMAIL,
            '--reset' => $hasTask ? true : null,
        ]));

        if ($exit !== 0) {
            $this->error(trim(Artisan::output()));
            return $exit;
        }

        $booking = Booking::where('assigned_team_leader_id', $teamLeader->id)->latest('id')->first();

        $this->info('Demo Team Leader ready.');
        $this->line('Email:    ' . self::EMAIL);
        $this->line('Password: ' . self::PASSWORD);
        $this->line('Task:     ' . ($booking?->booking_code ?? '-') . ' (status=' . ($booking?->status ?? '-') . ')');
        $this->line('Unit:     Demo Unit 01 · ' . self::UNIT_PLATE . ' · ' . $truckType->name . ' (' . $truckType->class . ')');

        return 0;
    }
}
