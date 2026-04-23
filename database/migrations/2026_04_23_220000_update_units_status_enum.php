<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add archived_at column
        Schema::table('units', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('status');
        });

        // PostgreSQL: replace CHECK constraint to allow new status values
        DB::statement("ALTER TABLE units DROP CONSTRAINT IF EXISTS units_status_check");
        DB::statement("ALTER TABLE units ADD CONSTRAINT units_status_check CHECK (status IN ('available','on_job','offline','disabled'))");

        // Rename existing maintenance records to offline
        DB::table('units')->where('status', 'maintenance')->update(['status' => 'offline']);
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE units DROP CONSTRAINT IF EXISTS units_status_check");
        DB::statement("ALTER TABLE units ADD CONSTRAINT units_status_check CHECK (status IN ('available','on_job','maintenance'))");

        DB::table('units')->where('status', 'offline')->update(['status' => 'maintenance']);

        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
