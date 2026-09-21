<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE vehicle_types DROP CONSTRAINT IF EXISTS vehicle_types_category_check');
        DB::statement("ALTER TABLE vehicle_types ADD CONSTRAINT vehicle_types_category_check CHECK (category IN ('2_wheeler', '4_wheeler', 'heavy_vehicle', 'cars_suvs', 'pickups_vans', 'trucks', 'other_heavy'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE vehicle_types DROP CONSTRAINT IF EXISTS vehicle_types_category_check');
        DB::statement("ALTER TABLE vehicle_types ADD CONSTRAINT vehicle_types_category_check CHECK (category IN ('2_wheeler', '4_wheeler', 'heavy_vehicle'))");
    }
};
