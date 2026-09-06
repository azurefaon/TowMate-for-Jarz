<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * bookings.distance_km / quotations.distance_km were both decimal(*, 2) —
 * the mobile app computes and submits distance with full double precision
 * (e.g. 5.8185 km), but the DB column silently truncated it to 2dp on
 * insert (e.g. 5.82 km). Any later server-side fee recompute off the
 * stored value then drifted from the customer's original estimate by a
 * few centavos (a real, reported bug — e.g. 3,411.02 shown to the
 * customer vs 3,411.52 recomputed by the dispatcher panel for the same
 * trip). Widening to 4 decimal places (~0.1m resolution) preserves enough
 * precision for the fee formula to round-trip exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bookings ALTER COLUMN distance_km TYPE decimal(10,4)');
        DB::statement('ALTER TABLE quotations ALTER COLUMN distance_km TYPE decimal(10,4)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings ALTER COLUMN distance_km TYPE decimal(10,2)');
        DB::statement('ALTER TABLE quotations ALTER COLUMN distance_km TYPE decimal(8,2)');
    }
};
