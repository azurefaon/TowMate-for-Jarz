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
        TruckType::updateOrCreate(
            ['name' => 'Sedan'],
            [
                'base_rate' => 500.00,
                'per_km_rate' => 50.00,
                'max_tonnage' => 2.0,
                'description' => 'Standard sedan vehicle towing',
                'status' => 'active'
            ]
        );

        TruckType::updateOrCreate(
            ['name' => 'SUV'],
            [
                'base_rate' => 700.00,
                'per_km_rate' => 70.00,
                'max_tonnage' => 3.0,
                'description' => 'SUV and crossover vehicle towing',
                'status' => 'active'
            ]
        );

        TruckType::updateOrCreate(
            ['name' => 'Truck'],
            [
                'base_rate' => 1000.00,
                'per_km_rate' => 100.00,
                'max_tonnage' => 5.0,
                'description' => 'Commercial truck and heavy vehicle towing',
                'status' => 'active'
            ]
        );

        TruckType::updateOrCreate(
            ['name' => 'Motorcycle'],
            [
                'base_rate' => 300.00,
                'per_km_rate' => 30.00,
                'max_tonnage' => 0.5,
                'description' => 'Motorcycle towing service',
                'status' => 'active'
            ]
        );
    }
}
