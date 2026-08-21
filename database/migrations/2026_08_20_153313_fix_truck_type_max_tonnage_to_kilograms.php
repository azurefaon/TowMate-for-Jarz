<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The "Capacity (kg)" field on Truck Class was originally seeded/entered in tons
     * (e.g. 4.50) before it was relabeled to kilograms. Correct the known duty classes
     * to the kilogram boundaries from the Towing Rate sheet. Guarded to only touch rows
     * still holding an obviously-stale tons-scale value, so any capacity an Owner has
     * already corrected by hand is left untouched.
     */
    public function up(): void
    {
        $corrections = [
            'light' => 4500,
            'medium' => 7500,
            'heavy' => 7501,
        ];

        foreach ($corrections as $class => $kilograms) {
            DB::table('truck_types')
                ->where('class', $class)
                ->where('max_tonnage', '<', 100)
                ->update(['max_tonnage' => $kilograms]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data correction only — not reversible to the previous (incorrect) values.
    }
};
