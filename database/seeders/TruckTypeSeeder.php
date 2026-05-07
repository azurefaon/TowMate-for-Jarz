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
                'base_rate'   => 500.00,
                'per_km_rate' => 0.00,
                'max_tonnage' => 2.00,
                'description' => 'Motorcycles, scooters, tricycles, and small to mid-size cars.',
                'status'      => 'active',
            ],
            [
                'name'        => 'Medium Duty',
                'class'       => 'medium',
                'base_rate'   => 800.00,
                'per_km_rate' => 0.00,
                'max_tonnage' => 5.00,
                'description' => 'SUVs, crossovers, vans, pick-up trucks, and AUVs.',
                'status'      => 'active',
            ],
            [
                'name'        => 'Heavy Duty',
                'class'       => 'heavy',
                'base_rate'   => 1500.00,
                'per_km_rate' => 0.00,
                'max_tonnage' => 20.00,
                'description' => 'Buses, 6-wheeler and 10-wheeler trucks, and large cargo vehicles.',
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
