<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected function forward(): array
    {
        return [
            'Sedan' => ['category' => 'cars_suvs', 'display_order' => 10],
            'Hatchback' => ['category' => 'cars_suvs', 'display_order' => 20],
            'Crossover' => ['category' => 'cars_suvs', 'display_order' => 30],
            'Pickup Truck' => ['category' => 'pickups_vans', 'display_order' => 40],
            'Minibus' => ['category' => 'pickups_vans', 'display_order' => 50],
            '10-Wheeler Truck' => ['category' => 'trucks', 'display_order' => 60],
            'Dump Truck' => ['category' => 'trucks', 'display_order' => 70],
            'Motorcycle' => ['display_order' => 80],
            'Scooter / E-Scooter' => ['display_order' => 90],
            'Bicycle / E-Bike' => ['display_order' => 100],
            'Tricycle' => ['display_order' => 110],
            'AUV / MPV' => ['display_order' => 120],
            'SUV' => ['display_order' => 130],
            'Van / L300' => ['display_order' => 140],
            'Bus' => ['display_order' => 150],
            'Elf / 6-Wheeler' => ['display_order' => 160],
            'Cargo Truck' => ['display_order' => 170],
        ];
    }

    protected function reverse(): array
    {
        return [
            'Sedan' => ['category' => '4_wheeler', 'display_order' => 5],
            'Hatchback' => ['category' => '4_wheeler', 'display_order' => 6],
            'Crossover' => ['category' => '4_wheeler', 'display_order' => 9],
            'Pickup Truck' => ['category' => '4_wheeler', 'display_order' => 10],
            'Minibus' => ['category' => 'heavy_vehicle', 'display_order' => 12],
            '10-Wheeler Truck' => ['category' => 'heavy_vehicle', 'display_order' => 15],
            'Dump Truck' => ['category' => 'heavy_vehicle', 'display_order' => 17],
            'Motorcycle' => ['display_order' => 1],
            'Scooter / E-Scooter' => ['display_order' => 2],
            'Bicycle / E-Bike' => ['display_order' => 3],
            'Tricycle' => ['display_order' => 4],
            'AUV / MPV' => ['display_order' => 7],
            'SUV' => ['display_order' => 8],
            'Van / L300' => ['display_order' => 11],
            'Bus' => ['display_order' => 13],
            'Elf / 6-Wheeler' => ['display_order' => 14],
            'Cargo Truck' => ['display_order' => 16],
        ];
    }

    public function up(): void
    {
        foreach ($this->forward() as $name => $values) {
            DB::table('vehicle_types')->where('name', $name)->update($values);
        }
    }

    public function down(): void
    {
        foreach ($this->reverse() as $name => $values) {
            DB::table('vehicle_types')->where('name', $name)->update($values);
        }
    }
};
