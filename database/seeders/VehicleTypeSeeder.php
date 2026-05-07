<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TruckType;
use App\Models\VehicleType;

class VehicleTypeSeeder extends Seeder
{
    public function run(): void
    {
        $light  = TruckType::where('name', 'Light Duty')->first();
        $medium = TruckType::where('name', 'Medium Duty')->first();
        $heavy  = TruckType::where('name', 'Heavy Duty')->first();

        // ── 2-Wheeler types ────────────────────────────────────────────────────
        // Towed by Light Duty only
        $twoWheelers = [
            ['name' => 'Motorcycle',         'category' => '2_wheeler', 'description' => 'Standard motorcycles and dirt bikes.',      'display_order' => 1],
            ['name' => 'Scooter / E-Scooter','category' => '2_wheeler', 'description' => 'Gas and electric scooters.',               'display_order' => 2],
            ['name' => 'Bicycle / E-Bike',   'category' => '2_wheeler', 'description' => 'Bicycles and electric bikes.',             'display_order' => 3],
            ['name' => 'Tricycle',           'category' => '2_wheeler', 'description' => 'Motorized and e-tricycles (trike).',       'display_order' => 4],
        ];

        // ── 4-Wheeler types ────────────────────────────────────────────────────
        // Light Duty handles smaller 4-wheelers; Medium Duty handles the rest
        $lightFourWheelers = [
            ['name' => 'Sedan',              'category' => '4_wheeler', 'description' => 'Standard 4-door sedans and small cars.',   'display_order' => 5],
            ['name' => 'Hatchback',          'category' => '4_wheeler', 'description' => 'Compact hatchbacks and city cars.',        'display_order' => 6],
            ['name' => 'AUV / MPV',          'category' => '4_wheeler', 'description' => 'Asian Utility Vehicles and minivans.',     'display_order' => 7],
        ];

        $mediumFourWheelers = [
            ['name' => 'SUV',                'category' => '4_wheeler', 'description' => 'Sport Utility Vehicles and full-size SUVs.','display_order' => 8],
            ['name' => 'Crossover',          'category' => '4_wheeler', 'description' => 'Compact and mid-size crossovers.',         'display_order' => 9],
            ['name' => 'Pickup Truck',       'category' => '4_wheeler', 'description' => 'Single- and double-cab pickup trucks.',    'display_order' => 10],
            ['name' => 'Van / L300',         'category' => '4_wheeler', 'description' => 'Passenger and cargo vans.',               'display_order' => 11],
        ];

        // ── Heavy vehicle types ────────────────────────────────────────────────
        // Towed by Heavy Duty only
        $heavyVehicles = [
            ['name' => 'Minibus',            'category' => 'heavy_vehicle', 'description' => 'UV express and small buses.',         'display_order' => 12],
            ['name' => 'Bus',                'category' => 'heavy_vehicle', 'description' => 'Full-size passenger buses.',          'display_order' => 13],
            ['name' => 'Elf / 6-Wheeler',    'category' => 'heavy_vehicle', 'description' => 'Elf trucks and 6-wheeler trucks.',    'display_order' => 14],
            ['name' => '10-Wheeler Truck',   'category' => 'heavy_vehicle', 'description' => '10-wheeler and wing-van trucks.',     'display_order' => 15],
            ['name' => 'Cargo Truck',        'category' => 'heavy_vehicle', 'description' => 'Articulated and container trucks.',   'display_order' => 16],
            ['name' => 'Dump Truck',         'category' => 'heavy_vehicle', 'description' => 'Dump and construction trucks.',       'display_order' => 17],
        ];

        // ── Upsert all vehicle types and attach to truck classes ───────────────

        foreach ($twoWheelers as $data) {
            $vt = VehicleType::updateOrCreate(['name' => $data['name']], array_merge($data, ['status' => 'active']));
            if ($light) {
                $vt->truckTypes()->syncWithoutDetaching([$light->id]);
            }
        }

        foreach ($lightFourWheelers as $data) {
            $vt = VehicleType::updateOrCreate(['name' => $data['name']], array_merge($data, ['status' => 'active']));
            $ids = [];
            if ($light)  $ids[] = $light->id;
            if ($medium) $ids[] = $medium->id;
            if (!empty($ids)) $vt->truckTypes()->syncWithoutDetaching($ids);
        }

        foreach ($mediumFourWheelers as $data) {
            $vt = VehicleType::updateOrCreate(['name' => $data['name']], array_merge($data, ['status' => 'active']));
            if ($medium) {
                $vt->truckTypes()->syncWithoutDetaching([$medium->id]);
            }
        }

        foreach ($heavyVehicles as $data) {
            $vt = VehicleType::updateOrCreate(['name' => $data['name']], array_merge($data, ['status' => 'active']));
            if ($heavy) {
                $vt->truckTypes()->syncWithoutDetaching([$heavy->id]);
            }
        }

        $this->command->info('Vehicle types seeded and linked to truck classes.');
    }
}
