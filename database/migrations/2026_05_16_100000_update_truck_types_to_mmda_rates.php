<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Update existing truck type rows to MMDA-compliant base rates
        DB::table('truck_types')->where('class', 'light')->update([
            'base_rate'   => 1500.00,
            'per_km_rate' => 300.00,
            'max_tonnage' => 4.50,
            'description' => '4,500 kg and below — Sedans, hatchbacks, SUVs, motorcycles, pickups (unloaded), vans, MPVs.',
        ]);

        DB::table('truck_types')->where('class', 'medium')->update([
            'base_rate'   => 2500.00,
            'per_km_rate' => 300.00,
            'max_tonnage' => 7.50,
            'description' => '4,501–7,500 kg — Loaded pickups, small cargo trucks, minibuses, modern jeepneys, ambulances.',
        ]);

        DB::table('truck_types')->where('class', 'heavy')->update([
            'base_rate'   => 4500.00,
            'per_km_rate' => 300.00,
            'max_tonnage' => 999.00,
            'description' => '7,501 kg and above — 10-wheelers, wing vans, trailer trucks, buses, heavy equipment.',
        ]);
    }

    public function down(): void
    {
        DB::table('truck_types')->where('class', 'light')->update(['base_rate' => 500.00,  'per_km_rate' => 0.00, 'max_tonnage' => 2.00]);
        DB::table('truck_types')->where('class', 'medium')->update(['base_rate' => 800.00,  'per_km_rate' => 0.00, 'max_tonnage' => 5.00]);
        DB::table('truck_types')->where('class', 'heavy')->update(['base_rate' => 1500.00, 'per_km_rate' => 0.00, 'max_tonnage' => 20.00]);
    }
};
