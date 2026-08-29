<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds 'locked' as an allowed users.status value, for the new customer
     * inactivity auto-lock feature. Follows the same pattern as
     * 2026_08_20_173232_restore_maintenance_status_on_units_table.php.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','inactive','locked'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('users') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::table('users')->where('status', 'locked')->update(['status' => 'inactive']);

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','inactive'))");
    }
};
