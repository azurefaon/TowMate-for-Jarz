<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected function rows(): array
    {
        return [
            ['slug' => 'cars_suvs', 'name' => 'Cars & SUVs'],
            ['slug' => 'pickups_vans', 'name' => 'Pickups & Vans'],
            ['slug' => 'trucks', 'name' => 'Trucks'],
            ['slug' => 'other_heavy', 'name' => 'Other / Heavy Vehicles'],
            ['slug' => '2_wheeler', 'name' => '2-Wheeler (legacy)'],
            ['slug' => '4_wheeler', 'name' => '4-Wheeler (legacy)'],
            ['slug' => 'heavy_vehicle', 'name' => 'Heavy Vehicle (legacy)'],
        ];
    }

    public function up(): void
    {
        foreach ($this->rows() as $row) {
            DB::table('vehicle_categories')->updateOrInsert(
                ['slug' => $row['slug']],
                ['name' => $row['name'], 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        DB::table('vehicle_categories')->whereIn('slug', array_column($this->rows(), 'slug'))->delete();
    }
};
