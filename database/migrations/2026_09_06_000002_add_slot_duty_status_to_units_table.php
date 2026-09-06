<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Driver/Crew Duty, unlike Team Leader Duty, is tracked per Unit slot
     * rather than per person — Driver/Crew are usually free-text names on
     * the Unit (units.driver_name / crew_member_1_name / crew_member_2_name),
     * with no stable account identity to attach a durable per-person trait
     * to. Nullable, null meaning "available" (no behavior change until a
     * Dispatcher explicitly sets one).
     */
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->string('driver_duty_status')->nullable()->after('driver_name');
            $table->string('crew_1_duty_status')->nullable()->after('crew_member_1_name');
            $table->string('crew_2_duty_status')->nullable()->after('crew_member_2_name');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement(
                "ALTER TABLE units ADD CONSTRAINT units_driver_duty_status_check CHECK (driver_duty_status IN ('available','unavailable'))"
            );
            \Illuminate\Support\Facades\DB::statement(
                "ALTER TABLE units ADD CONSTRAINT units_crew_1_duty_status_check CHECK (crew_1_duty_status IN ('available','unavailable'))"
            );
            \Illuminate\Support\Facades\DB::statement(
                "ALTER TABLE units ADD CONSTRAINT units_crew_2_duty_status_check CHECK (crew_2_duty_status IN ('available','unavailable'))"
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE units DROP CONSTRAINT IF EXISTS units_driver_duty_status_check');
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE units DROP CONSTRAINT IF EXISTS units_crew_1_duty_status_check');
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE units DROP CONSTRAINT IF EXISTS units_crew_2_duty_status_check');
        }

        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn(['driver_duty_status', 'crew_1_duty_status', 'crew_2_duty_status']);
        });
    }
};
