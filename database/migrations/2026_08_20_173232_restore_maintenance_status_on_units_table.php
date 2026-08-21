<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An earlier migration (2026_04_23_220000_update_units_status_enum) replaced the
     * "units_status_check" constraint with ('available','on_job','offline','disabled'),
     * dropping 'maintenance'. Nothing in the app ever adopted 'offline'/'disabled' for
     * units.status — every controller (UnitController, Admin\AvailableUnitsController,
     * DispatchController, MonitoringController, ControlCenterService) still reads/writes
     * 'maintenance', so any Disable/toggle action started failing with a check-constraint
     * violation once running against Postgres. Restore 'maintenance' as an allowed value
     * (keeping 'offline'/'disabled' too, in case any existing row already holds one) and
     * migrate any rows currently sitting in those states back to 'maintenance'.
     */
    public function up(): void
    {
        if (! Schema::hasTable('units') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::table('units')->whereIn('status', ['offline', 'disabled'])->update(['status' => 'maintenance']);

        DB::statement('ALTER TABLE units DROP CONSTRAINT IF EXISTS units_status_check');
        DB::statement("ALTER TABLE units ADD CONSTRAINT units_status_check CHECK (status IN ('available','on_job','maintenance'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('units') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE units DROP CONSTRAINT IF EXISTS units_status_check');
        DB::statement("ALTER TABLE units ADD CONSTRAINT units_status_check CHECK (status IN ('available','on_job','offline','disabled'))");
    }
};
