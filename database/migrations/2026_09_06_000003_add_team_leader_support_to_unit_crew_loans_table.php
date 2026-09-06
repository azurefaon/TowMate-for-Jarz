<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extends the existing unit_crew_loans table (built for free-text
     * Driver/Crew slot borrowing) to also represent Team Leader borrowing,
     * rather than creating a second, competing loan mechanism.
     *
     * Two additive changes only:
     * 1. person_user_id (nullable FK to users) — Team Leader is a real,
     *    stable account (unlike Driver/Crew, which are usually just a name
     *    string), so a TL loan needs to carry the actual user id to restore
     *    the exact units.team_leader_id relationship on return. Left null
     *    for existing/future Driver/Crew loans, which continue to work by
     *    person_name alone exactly as before.
     * 2. from_slot / to_slot CHECK constraints widened to also allow
     *    'team_leader', alongside the existing driver_1/driver_2/
     *    crew_member_1/crew_member_2 values.
     *
     * No existing rows are touched; no columns are dropped.
     */
    public function up(): void
    {
        Schema::table('unit_crew_loans', function (Blueprint $table) {
            $table->foreignId('person_user_id')->nullable()->after('person_name')->constrained('users')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE unit_crew_loans DROP CONSTRAINT IF EXISTS unit_crew_loans_slot_check');
            DB::statement(
                "ALTER TABLE unit_crew_loans ADD CONSTRAINT unit_crew_loans_slot_check " .
                "CHECK (from_slot IN ('team_leader','driver_1','driver_2','crew_member_1','crew_member_2'))"
            );

            DB::statement('ALTER TABLE unit_crew_loans DROP CONSTRAINT IF EXISTS unit_crew_loans_to_slot_check');
            DB::statement(
                "ALTER TABLE unit_crew_loans ADD CONSTRAINT unit_crew_loans_to_slot_check " .
                "CHECK (to_slot IN ('team_leader','driver_1','driver_2','crew_member_1','crew_member_2'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE unit_crew_loans DROP CONSTRAINT IF EXISTS unit_crew_loans_slot_check');
            DB::statement(
                "ALTER TABLE unit_crew_loans ADD CONSTRAINT unit_crew_loans_slot_check " .
                "CHECK (from_slot IN ('driver_1','driver_2','crew_member_1','crew_member_2'))"
            );

            DB::statement('ALTER TABLE unit_crew_loans DROP CONSTRAINT IF EXISTS unit_crew_loans_to_slot_check');
            DB::statement(
                "ALTER TABLE unit_crew_loans ADD CONSTRAINT unit_crew_loans_to_slot_check " .
                "CHECK (to_slot IN ('driver_1','driver_2','crew_member_1','crew_member_2'))"
            );
        }

        Schema::table('unit_crew_loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('person_user_id');
        });
    }
};
