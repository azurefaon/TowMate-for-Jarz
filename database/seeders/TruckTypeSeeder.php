<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\TruckType;

class TruckTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $truckTypes = [
            [
                'name' => 'Motorcycle',
                'base_rate' => 100.00,
                'per_km_rate' => 200.00,
                'max_tonnage' => 0.50,
                'description' => 'Motorcycle towing service',
                'status' => 'active',
            ],
            [
                'name' => 'Sedan',
                'base_rate' => 500.00,
                'per_km_rate' => 200.00,
                'max_tonnage' => 2.00,
                'description' => 'Standard sedan vehicle towing',
                'status' => 'active',
            ],
            [
                'name' => 'SUV',
                'base_rate' => 700.00,
                'per_km_rate' => 200.00,
                'max_tonnage' => 3.00,
                'description' => 'SUV and crossover vehicle towing',
                'status' => 'active',
            ],
            [
                'name' => 'Truck',
                'base_rate' => 1000.00,
                'per_km_rate' => 200.00,
                'max_tonnage' => 5.00,
                'description' => 'Commercial truck and heavy vehicle towing',
                'status' => 'active',
            ],
        ];

        foreach ($truckTypes as $truckType) {
            TruckType::updateOrCreate(
                ['name' => $truckType['name']],
                $truckType
            );
        }
    }
}
