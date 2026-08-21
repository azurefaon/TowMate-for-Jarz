<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The Heavy Duty class was still holding a stale placeholder capacity (999) that
     * the previous data-correction migration's <100 guard didn't catch, since 999 is
     * neither a plausible leftover tons value nor a valid kilogram capacity for a
     * "7501 kg and above" duty class. Set it to the Towing Rate sheet's threshold.
     */
    public function up(): void
    {
        DB::table('truck_types')
            ->where('class', 'heavy')
            ->where('max_tonnage', 999)
            ->update(['max_tonnage' => 7501]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data correction only — not reversible to the previous (incorrect) value.
    }
};
