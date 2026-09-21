<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected function truckTypeId(string $name): ?int
    {
        return DB::table('truck_types')->where('name', $name)->value('id');
    }

    protected function vehicleTypeId(string $name): ?int
    {
        return DB::table('vehicle_types')->where('name', $name)->value('id');
    }

    protected function syncRequiredTruckType(int $vehicleTypeId, int $truckTypeId): void
    {
        DB::table('vehicle_type_truck_type')->where('vehicle_type_id', $vehicleTypeId)->delete();
        DB::table('vehicle_type_truck_type')->insert([
            'vehicle_type_id' => $vehicleTypeId,
            'truck_type_id' => $truckTypeId,
        ]);
    }

    protected function insertVehicleType(string $name, string $category, int $requiredTruckTypeId, int $displayOrder): void
    {
        if ($this->vehicleTypeId($name) !== null) {
            return;
        }

        $id = DB::table('vehicle_types')->insertGetId([
            'name' => $name,
            'category' => $category,
            'weight_kg' => null,
            'required_truck_type_id' => $requiredTruckTypeId,
            'display_order' => $displayOrder,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->syncRequiredTruckType($id, $requiredTruckTypeId);
    }

    public function up(): void
    {
        $light = $this->truckTypeId('Light Duty');
        $medium = $this->truckTypeId('Medium Duty');
        $heavy = $this->truckTypeId('Heavy Duty');

        if ($light === null || $medium === null || $heavy === null) {
            return;
        }

        $sedanId = $this->vehicleTypeId('Sedan');
        if ($sedanId !== null) {
            DB::table('vehicle_types')->where('id', $sedanId)->update([
                'required_truck_type_id' => $light,
                'weight_kg' => null,
            ]);
            $this->syncRequiredTruckType($sedanId, $light);
        }

        $pickupId = $this->vehicleTypeId('Pickup Truck');
        if ($pickupId !== null) {
            DB::table('vehicle_types')->where('id', $pickupId)->update([
                'required_truck_type_id' => $medium,
                'weight_kg' => null,
            ]);
            $this->syncRequiredTruckType($pickupId, $medium);
        }

        DB::table('vehicle_types')->whereIn('name', ['Bus', 'Cargo Truck'])->update(['category' => 'other_heavy']);

        $this->insertVehicleType('Compact SUV', 'cars_suvs', $light, 15);
        $this->insertVehicleType('Van', 'pickups_vans', $medium, 45);
        $this->insertVehicleType('Cement Mixer', 'trucks', $heavy, 75);

        DB::table('vehicle_types')->where('name', 'Van / L300')->update(['status' => 'inactive']);
    }

    public function down(): void
    {
        $medium = $this->truckTypeId('Medium Duty');
        $heavy = $this->truckTypeId('Heavy Duty');

        DB::table('vehicle_types')->where('name', 'Van / L300')->update(['status' => 'active']);

        foreach (['Compact SUV', 'Van', 'Cement Mixer'] as $name) {
            $id = $this->vehicleTypeId($name);
            if ($id !== null) {
                DB::table('vehicle_type_truck_type')->where('vehicle_type_id', $id)->delete();
                DB::table('vehicle_types')->where('id', $id)->delete();
            }
        }

        DB::table('vehicle_types')->whereIn('name', ['Bus', 'Cargo Truck'])->update(['category' => 'heavy_vehicle']);

        $pickupId = $this->vehicleTypeId('Pickup Truck');
        if ($pickupId !== null && $heavy !== null) {
            DB::table('vehicle_types')->where('id', $pickupId)->update([
                'required_truck_type_id' => $heavy,
                'weight_kg' => 7501,
            ]);
            $this->syncRequiredTruckType($pickupId, $heavy);
        }

        $sedanId = $this->vehicleTypeId('Sedan');
        if ($sedanId !== null && $medium !== null) {
            DB::table('vehicle_types')->where('id', $sedanId)->update([
                'required_truck_type_id' => $medium,
                'weight_kg' => 7500,
            ]);
            $this->syncRequiredTruckType($sedanId, $medium);
        }
    }
};
