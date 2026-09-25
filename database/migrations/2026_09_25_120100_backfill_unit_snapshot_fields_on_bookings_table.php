<?php

use App\Models\Unit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Unit::query()
            ->select('id', 'name', 'plate_number')
            ->orderBy('id')
            ->chunk(100, function ($units) {
                foreach ($units as $unit) {
                    DB::table('bookings')
                        ->where('assigned_unit_id', $unit->id)
                        ->update([
                            'assigned_unit_name' => $unit->name,
                            'assigned_unit_plate_number' => $unit->plate_number,
                        ]);

                    DB::table('bookings')
                        ->where('selected_unit_id', $unit->id)
                        ->update([
                            'selected_unit_name' => $unit->name,
                            'selected_unit_plate_number' => $unit->plate_number,
                        ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('bookings')->update([
            'assigned_unit_name' => null,
            'assigned_unit_plate_number' => null,
            'selected_unit_name' => null,
            'selected_unit_plate_number' => null,
        ]);
    }
};
