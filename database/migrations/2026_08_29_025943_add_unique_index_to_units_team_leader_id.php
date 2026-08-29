<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A plain unique index on a nullable column still allows multiple NULLs
     * on most drivers, but doesn't stop the same non-null team_leader_id from
     * landing on two rows if application-layer validation is ever bypassed
     * or races (which is exactly what happened — see UnitController). A
     * partial unique index enforces "at most one unit per team leader" at
     * the database layer regardless of which code path writes the column.
     */
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX units_team_leader_id_unique ON units (team_leader_id) WHERE team_leader_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS units_team_leader_id_unique');
    }
};
