<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * unit_crew_loans.slot only recorded the SOURCE unit's slot. borrowCrew() only
     * enforces that from_slot/to_slot share the same category (driver vs crew_member),
     * not the same slot number, so a Driver 1 -> Driver 2 borrow is possible — but with
     * a single slot column, returnCrew() has no way to know the destination used a
     * different slot, so it restores/clears the wrong columns. Split into from_slot
     * (rename of the existing column) and to_slot (new) so both sides are tracked.
     */
    public function up(): void
    {
        if (! Schema::hasTable('unit_crew_loans') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE unit_crew_loans RENAME COLUMN slot TO from_slot');

        DB::statement('ALTER TABLE unit_crew_loans ADD COLUMN to_slot VARCHAR(255) NULL');
        DB::statement(
            "ALTER TABLE unit_crew_loans ADD CONSTRAINT unit_crew_loans_to_slot_check ".
            "CHECK (to_slot IN ('driver_1','driver_2','crew_member_1','crew_member_2'))"
        );

        DB::table('unit_crew_loans')->update(['to_slot' => DB::raw('from_slot')]);

        DB::statement('ALTER TABLE unit_crew_loans ALTER COLUMN to_slot SET NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('unit_crew_loans') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE unit_crew_loans DROP CONSTRAINT IF EXISTS unit_crew_loans_to_slot_check');
        DB::statement('ALTER TABLE unit_crew_loans DROP COLUMN to_slot');
        DB::statement('ALTER TABLE unit_crew_loans RENAME COLUMN from_slot TO slot');
    }
};
