<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'assigned_team_leader_id')) {
                $table->foreignId('assigned_team_leader_id')
                    ->nullable()
                    ->after('assigned_unit_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('bookings', 'driver_name')) {
                $table->string('driver_name')->nullable()->after('quotation_number');
            }
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE `bookings` MODIFY `status` ENUM('requested','accepted','assigned','on_the_way','in_progress','waiting_verification','on_job','completed','rejected','cancelled') NOT NULL DEFAULT 'requested'");
        }
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'driver_name')) {
                $table->dropColumn('driver_name');
            }

            if (Schema::hasColumn('bookings', 'assigned_team_leader_id')) {
                $table->dropConstrainedForeignId('assigned_team_leader_id');
            }
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE `bookings` MODIFY `status` ENUM('requested','accepted','assigned','in_progress','waiting_verification','on_job','completed','rejected','cancelled') NOT NULL DEFAULT 'requested'");
        }
    }
};
