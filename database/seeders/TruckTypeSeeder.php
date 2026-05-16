<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TruckType;

class TruckTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'name'        => 'Light Duty',
                'class'       => 'light',
                'base_rate'   => 1500.00,
                'per_km_rate' => 300.00,
                'max_tonnage' => 4.50,
                'description' => '4,500 kg and below — Sedans, hatchbacks, SUVs, motorcycles, pickups (unloaded), vans, MPVs.',
                'status'      => 'active',
            ],
            [
                'name'        => 'Medium Duty',
                'class'       => 'medium',
                'base_rate'   => 2500.00,
                'per_km_rate' => 300.00,
                'max_tonnage' => 7.50,
                'description' => '4,501–7,500 kg — Loaded pickups, small cargo trucks, minibuses, modern jeepneys, ambulances.',
                'status'      => 'active',
            ],
            [
                'name'        => 'Heavy Duty',
                'class'       => 'heavy',
                'base_rate'   => 4500.00,
                'per_km_rate' => 300.00,
                'max_tonnage' => 999.00,
                'description' => '7,501 kg and above — 10-wheelers, wing vans, trailer trucks, buses, heavy equipment.',
                'status'      => 'active',
            ],
        ];

        foreach ($types as $data) {
            TruckType::updateOrCreate(['name' => $data['name']], $data);
        }

        // Deactivate the old incorrectly-named entries if they have no bookings
        TruckType::whereIn('name', ['Motorcycle', 'Sedan', 'SUV', 'Truck'])
            ->whereDoesntHave('bookings')
            ->update(['status' => 'inactive']);

        $this->command->info('Truck types seeded.');
    }
}
