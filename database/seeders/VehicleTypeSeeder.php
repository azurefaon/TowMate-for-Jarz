<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\VehicleType;
use App\Models\TruckType;

class VehicleTypeSeeder extends Seeder
{
    public function run(): void
    {
        // 2-Wheeler Vehicles
        $motorcycle = VehicleType::create([
            'name' => 'Motorcycle',
            'category' => '2_wheeler',
            'description' => 'Standard motorcycles and scooters',
            'display_order' => 1,
            'status' => 'active',
        ]);

        $scooter = VehicleType::create([
            'name' => 'Scooter',
            'category' => '2_wheeler',
            'description' => 'Electric and gas scooters',
            'display_order' => 2,
            'status' => 'active',
        ]);

        $tricycle = VehicleType::create([
            'name' => 'Tricycle',
            'category' => '2_wheeler',
            'description' => 'Motorized tricycles',
            'display_order' => 3,
            'status' => 'active',
        ]);

        // 4-Wheeler Vehicles
        $sedan = VehicleType::create([
            'name' => 'Sedan',
            'category' => '4_wheeler',
            'description' => 'Standard 4-door sedans',
            'display_order' => 4,
            'status' => 'active',
        ]);

        $suv = VehicleType::create([
            'name' => 'SUV',
            'category' => '4_wheeler',
            'description' => 'Sport Utility Vehicles',
            'display_order' => 5,
            'status' => 'active',
        ]);

        $pickup = VehicleType::create([
            'name' => 'Pickup Truck',
            'category' => '4_wheeler',
            'description' => 'Light pickup trucks',
            'display_order' => 6,
            'status' => 'active',
        ]);

        $van = VehicleType::create([
            'name' => 'Van',
            'category' => '4_wheeler',
            'description' => 'Passenger and cargo vans',
            'display_order' => 7,
            'status' => 'active',
        ]);

        // Heavy Vehicles
        $sixWheeler = VehicleType::create([
            'name' => '6-Wheeler Truck',
            'category' => 'heavy_vehicle',
            'description' => 'Medium duty trucks',
            'display_order' => 8,
            'status' => 'active',
        ]);

        $tenWheeler = VehicleType::create([
            'name' => '10-Wheeler Truck',
            'category' => 'heavy_vehicle',
            'description' => 'Heavy duty trucks',
            'display_order' => 9,
            'status' => 'active',
        ]);

        $cargoTruck = VehicleType::create([
            'name' => 'Cargo Truck',
            'category' => 'heavy_vehicle',
            'description' => 'Large cargo transport vehicles',
            'display_order' => 10,
            'status' => 'active',
        ]);

        // Link vehicle types to truck types (if truck types exist)
        // You can customize this based on your actual truck types
        
        // Example: Link 2-wheelers to motorcycle tow trucks
        $motorcycleTowTruck = TruckType::where('name', 'LIKE', '%motorcycle%')->first();
        if ($motorcycleTowTruck) {
            $motorcycle->truckTypes()->attach($motorcycleTowTruck->id);
            $scooter->truckTypes()->attach($motorcycleTowTruck->id);
            $tricycle->truckTypes()->attach($motorcycleTowTruck->id);
        }

        // Example: Link 4-wheelers to flatbed/wheel-lift trucks
        $flatbedTruck = TruckType::where('name', 'LIKE', '%flatbed%')->first();
        if ($flatbedTruck) {
            $sedan->truckTypes()->attach($flatbedTruck->id);
            $suv->truckTypes()->attach($flatbedTruck->id);
            $pickup->truckTypes()->attach($flatbedTruck->id);
            $van->truckTypes()->attach($flatbedTruck->id);
        }

        // Example: Link heavy vehicles to heavy duty trucks
        $heavyDutyTruck = TruckType::where('name', 'LIKE', '%heavy%')->first();
        if ($heavyDutyTruck) {
            $sixWheeler->truckTypes()->attach($heavyDutyTruck->id);
            $tenWheeler->truckTypes()->attach($heavyDutyTruck->id);
            $cargoTruck->truckTypes()->attach($heavyDutyTruck->id);
        }

        $this->command->info('Vehicle types seeded successfully!');
    }
}
