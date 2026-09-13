<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_types', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicle_types', 'required_truck_type_id')) {
                $table->foreignId('required_truck_type_id')
                    ->nullable()
                    ->after('category')
                    ->constrained('truck_types')
                    ->nullOnDelete();
            }
        });

        $unambiguous = DB::table('vehicle_type_truck_type')
            ->select('vehicle_type_id')
            ->groupBy('vehicle_type_id')
            ->havingRaw('count(*) = 1')
            ->pluck('vehicle_type_id');

        foreach ($unambiguous as $vehicleTypeId) {
            $truckTypeId = DB::table('vehicle_type_truck_type')
                ->where('vehicle_type_id', $vehicleTypeId)
                ->value('truck_type_id');

            DB::table('vehicle_types')
                ->where('id', $vehicleTypeId)
                ->whereNull('required_truck_type_id')
                ->update(['required_truck_type_id' => $truckTypeId]);
        }
    }

    public function down(): void
    {
        Schema::table('vehicle_types', function (Blueprint $table) {
            if (Schema::hasColumn('vehicle_types', 'required_truck_type_id')) {
                $table->dropConstrainedForeignId('required_truck_type_id');
            }
        });
    }
};
