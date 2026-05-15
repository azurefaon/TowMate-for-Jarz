<?php

namespace Database\Seeders;

use App\Models\TruckType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $truckType = TruckType::where('status', 'active')->first();

        if (! $truckType) {
            $this->command->warn('No active truck types found. Run TruckTypeSeeder first.');
            return;
        }

        $tls = User::where('role_id', 3)->whereNull('archived_at')->get();

        foreach ($tls as $tl) {
            if (Unit::where('team_leader_id', $tl->id)->exists()) {
                continue;
            }

            Unit::create([
                'name'           => ($tl->name ?? 'TL') . "'s Unit",
                'plate_number'   => 'UNIT-' . strtoupper(substr(md5((string) $tl->id), 0, 6)),
                'truck_type_id'  => $truckType->id,
                'team_leader_id' => $tl->id,
                'driver_name'    => $tl->name ?? '',
                'status'         => 'available',
            ]);

            $this->command->info("Created unit for TL: {$tl->name}");
        }
    }
}
