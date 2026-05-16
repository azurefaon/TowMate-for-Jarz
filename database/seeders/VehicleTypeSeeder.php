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

        // ── LIGHT vehicles (≤4,500 kg) — towed by Light Duty ──────────────────
        $lightVehicles = [
            ['name' => 'Sedan',                        'category' => '4_wheeler',    'description' => 'Toyota Vios, Honda City, Mitsubishi Mirage',          'display_order' => 1],
            ['name' => 'Hatchback',                    'category' => '4_wheeler',    'description' => 'Toyota Wigo, Honda Brio',                             'display_order' => 2],
            ['name' => 'Coupe / Sports Car',           'category' => '4_wheeler',    'description' => 'Toyota 86, Mazda MX-5',                               'display_order' => 3],
            ['name' => 'Compact SUV',                  'category' => '4_wheeler',    'description' => 'Toyota Raize, Kia Stonic',                            'display_order' => 4],
            ['name' => 'Mid-size SUV',                 'category' => '4_wheeler',    'description' => 'Fortuner, Montero Sport, Everest',                    'display_order' => 5],
            ['name' => 'Pickup Truck (light/unloaded)','category' => '4_wheeler',    'description' => 'Hilux, Ranger, Navara, D-Max (unloaded)',             'display_order' => 6],
            ['name' => 'MPV / Family Van',             'category' => '4_wheeler',    'description' => 'Innova, Avanza, Ertiga',                              'display_order' => 7],
            ['name' => 'Passenger Van',                'category' => '4_wheeler',    'description' => 'HiAce, Urvan',                                        'display_order' => 8],
            ['name' => 'Crossover',                    'category' => '4_wheeler',    'description' => 'Corolla Cross, CR-V, CX-5',                           'display_order' => 9],
            ['name' => 'Motorcycle / Big Bike',        'category' => '2_wheeler',    'description' => 'NMAX, ADV, Rebel 500',                                'display_order' => 10],
            ['name' => 'Small Utility Vehicle',        'category' => '4_wheeler',    'description' => 'Owner-type jeep, multicab',                           'display_order' => 11],
            ['name' => 'Traditional Jeepney',          'category' => '4_wheeler',    'description' => 'Public utility jeepney (unloaded)',                   'display_order' => 12],
        ];

        // ── MEDIUM vehicles (4,501–7,500 kg) — towed by Medium Duty ───────────
        $mediumVehicles = [
            ['name' => 'Fully Loaded Pickup',          'category' => '4_wheeler',    'description' => 'Loaded Hilux, Ranger',                               'display_order' => 13],
            ['name' => 'Small Cargo Truck',            'category' => 'heavy_vehicle','description' => 'Isuzu Elf, Mitsubishi Canter',                        'display_order' => 14],
            ['name' => 'Light Commercial Truck',       'category' => 'heavy_vehicle','description' => 'Isuzu Forward (light setup)',                         'display_order' => 15],
            ['name' => 'Mini Dump Truck',              'category' => 'heavy_vehicle','description' => 'Small construction dump truck',                       'display_order' => 16],
            ['name' => 'Minibus',                      'category' => 'heavy_vehicle','description' => 'Toyota Coaster',                                      'display_order' => 17],
            ['name' => 'Modern Jeepney',               'category' => 'heavy_vehicle','description' => 'Modern PUV units',                                   'display_order' => 18],
            ['name' => 'Ambulance',                    'category' => '4_wheeler',    'description' => 'Commercial ambulance units',                          'display_order' => 19],
            ['name' => 'Refrigerated Truck (small)',   'category' => 'heavy_vehicle','description' => 'Reefer delivery truck',                               'display_order' => 20],
            ['name' => 'Service / Utility Truck',      'category' => 'heavy_vehicle','description' => 'Maintenance trucks',                                  'display_order' => 21],
            ['name' => 'Flatbed Truck (small)',        'category' => 'heavy_vehicle','description' => 'Small hauling flatbeds',                              'display_order' => 22],
            ['name' => 'Water Delivery Truck',         'category' => 'heavy_vehicle','description' => 'Small tanker trucks',                                 'display_order' => 23],
        ];

        // ── HEAVY vehicles (≥7,501 kg) — towed by Heavy Duty ─────────────────
        $heavyVehicles = [
            ['name' => 'Large Cargo Truck',            'category' => 'heavy_vehicle','description' => 'Heavy-duty cargo truck',                             'display_order' => 24],
            ['name' => '10-Wheeler Truck',             'category' => 'heavy_vehicle','description' => 'Large freight truck',                                'display_order' => 25],
            ['name' => '12-Wheeler Truck',             'category' => 'heavy_vehicle','description' => 'Heavy freight truck',                                'display_order' => 26],
            ['name' => 'Wing Van Truck',               'category' => 'heavy_vehicle','description' => 'Logistics wing van',                                 'display_order' => 27],
            ['name' => 'Trailer Truck',                'category' => 'heavy_vehicle','description' => 'Semi-trailer truck',                                 'display_order' => 28],
            ['name' => 'Tractor Head',                 'category' => 'heavy_vehicle','description' => 'Prime mover truck',                                  'display_order' => 29],
            ['name' => 'Container Truck',              'category' => 'heavy_vehicle','description' => 'Shipping container hauler',                          'display_order' => 30],
            ['name' => 'Cement Mixer',                 'category' => 'heavy_vehicle','description' => 'Concrete mixer truck',                               'display_order' => 31],
            ['name' => 'Large Dump Truck',             'category' => 'heavy_vehicle','description' => 'Heavy construction dump truck',                      'display_order' => 32],
            ['name' => 'Fire Truck',                   'category' => 'heavy_vehicle','description' => 'Bureau of Fire truck',                               'display_order' => 33],
            ['name' => 'Fuel Tanker',                  'category' => 'heavy_vehicle','description' => 'Gasoline / diesel tanker',                           'display_order' => 34],
            ['name' => 'Large Bus',                    'category' => 'heavy_vehicle','description' => 'Provincial bus, city bus',                           'display_order' => 35],
            ['name' => 'Heavy Equipment Transport',    'category' => 'heavy_vehicle','description' => 'Backhoe / excavator hauler',                         'display_order' => 36],
            ['name' => 'Garbage Truck',                'category' => 'heavy_vehicle','description' => 'Waste collection truck',                             'display_order' => 37],
            ['name' => 'Large Refrigerated Truck',     'category' => 'heavy_vehicle','description' => 'Large reefer truck',                                 'display_order' => 38],
        ];

        foreach ($lightVehicles as $data) {
            $vt = VehicleType::updateOrCreate(['name' => $data['name']], array_merge($data, ['status' => 'active']));
            if ($light) $vt->truckTypes()->syncWithoutDetaching([$light->id]);
        }

        foreach ($mediumVehicles as $data) {
            $vt = VehicleType::updateOrCreate(['name' => $data['name']], array_merge($data, ['status' => 'active']));
            if ($medium) $vt->truckTypes()->syncWithoutDetaching([$medium->id]);
        }

        foreach ($heavyVehicles as $data) {
            $vt = VehicleType::updateOrCreate(['name' => $data['name']], array_merge($data, ['status' => 'active']));
            if ($heavy) $vt->truckTypes()->syncWithoutDetaching([$heavy->id]);
        }

        // Deactivate old vehicle types that no longer fit the MMDA classification
        VehicleType::whereIn('name', [
            'Scooter / E-Scooter', 'Bicycle / E-Bike', 'Tricycle',
            'AUV / MPV', 'SUV', 'Van / L300',
            'Bus', 'Elf / 6-Wheeler', 'Cargo Truck', 'Dump Truck',
        ])->whereDoesntHave('bookings')->update(['status' => 'inactive']);

        $this->command->info('Vehicle types seeded (MMDA classification) and linked to truck classes.');
    }
}
