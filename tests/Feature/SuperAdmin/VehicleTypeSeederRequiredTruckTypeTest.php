<?php

use App\Models\TruckType;
use App\Models\VehicleType;
use App\Services\BookingService;
use Database\Seeders\VehicleTypeSeeder;

function vtsSeedTruckTypes(): array
{
    $light = TruckType::create([
        'name' => 'Light Duty',
        'class' => 'light',
        'base_rate' => 1500,
        'per_km_rate' => 60,
        'status' => 'active',
    ]);

    $medium = TruckType::create([
        'name' => 'Medium Duty',
        'class' => 'medium',
        'base_rate' => 2500,
        'per_km_rate' => 80,
        'status' => 'active',
    ]);

    $heavy = TruckType::create([
        'name' => 'Heavy Duty',
        'class' => 'heavy',
        'base_rate' => 4000,
        'per_km_rate' => 120,
        'status' => 'active',
    ]);

    return [$light, $medium, $heavy];
}

test('the seeder populates required_truck_type_id for every vehicle type it creates', function () {
    [$light, $medium, $heavy] = vtsSeedTruckTypes();

    $this->seed(VehicleTypeSeeder::class);

    $sedan = VehicleType::where('name', 'Sedan')->firstOrFail();
    expect($sedan->required_truck_type_id)->toBe($light->id);
    expect($sedan->truckTypes()->pluck('truck_types.id')->all())->toBe([$light->id]);

    $miniDump = VehicleType::where('name', 'Mini Dump Truck')->firstOrFail();
    expect($miniDump->required_truck_type_id)->toBe($medium->id);

    $trailerTruck = VehicleType::where('name', 'Trailer Truck')->firstOrFail();
    expect($trailerTruck->required_truck_type_id)->toBe($heavy->id);
});

test('a seeded vehicle type resolves a bookable truck type through the current booking matching logic', function () {
    vtsSeedTruckTypes();

    $this->seed(VehicleTypeSeeder::class);

    $sedan = VehicleType::where('name', 'Sedan')->firstOrFail();

    [$truckType, $error] = app(BookingService::class)->resolveRequiredTruckType($sedan->id);

    expect($error)->toBeNull();
    expect($truckType)->not->toBeNull();
    expect($truckType->name)->toBe('Light Duty');
});

test('re-running the seeder heals a vehicle type that has a pivot link but a null required_truck_type_id', function () {
    [$light] = vtsSeedTruckTypes();

    $sedan = VehicleType::create([
        'name' => 'Sedan',
        'category' => '4_wheeler',
        'description' => 'Legacy pivot-only row',
        'display_order' => 1,
        'status' => 'active',
    ]);
    $sedan->truckTypes()->syncWithoutDetaching([$light->id]);

    expect($sedan->required_truck_type_id)->toBeNull();

    $this->seed(VehicleTypeSeeder::class);

    $sedan->refresh();
    expect($sedan->required_truck_type_id)->toBe($light->id);
});

test('re-running the seeder does not overwrite a customized required_truck_type_id', function () {
    [$light, $medium] = vtsSeedTruckTypes();

    $sedan = VehicleType::create([
        'name' => 'Sedan',
        'category' => '4_wheeler',
        'description' => 'Manually recategorized by an admin',
        'display_order' => 1,
        'status' => 'active',
        'required_truck_type_id' => $medium->id,
    ]);
    $sedan->truckTypes()->sync([$medium->id]);

    $this->seed(VehicleTypeSeeder::class);

    $sedan->refresh();
    expect($sedan->required_truck_type_id)->toBe($medium->id);
});

test('re-running the seeder twice in a row keeps every vehicle type bookable', function () {
    vtsSeedTruckTypes();

    $this->seed(VehicleTypeSeeder::class);
    $this->seed(VehicleTypeSeeder::class);

    $unbookable = VehicleType::where('status', 'active')->whereNull('required_truck_type_id')->count();

    expect($unbookable)->toBe(0);
});
