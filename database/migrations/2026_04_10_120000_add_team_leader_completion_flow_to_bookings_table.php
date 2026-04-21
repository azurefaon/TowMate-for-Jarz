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
            if (!Schema::hasColumn('bookings', 'completion_requested_at')) {
                $table->timestamp('completion_requested_at')->nullable()->after('assigned_at');
            }

            if (!Schema::hasColumn('bookings', 'customer_verified_at')) {
                $table->timestamp('customer_verified_at')->nullable()->after('completion_requested_at');
            }

            if (!Schema::hasColumn('bookings', 'customer_verification_status')) {
                $table->string('customer_verification_status')->nullable()->after('customer_verified_at');
            }

            if (!Schema::hasColumn('bookings', 'customer_verification_note')) {
                $table->text('customer_verification_note')->nullable()->after('customer_verification_status');
            }
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE `bookings` MODIFY `status` ENUM('requested','accepted','assigned','in_progress','waiting_verification','on_job','completed','rejected','cancelled') NOT NULL DEFAULT 'requested'");
        }
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'customer_verification_note')) {
                $table->dropColumn('customer_verification_note');
            }

            if (Schema::hasColumn('bookings', 'customer_verification_status')) {
                $table->dropColumn('customer_verification_status');
            }

            if (Schema::hasColumn('bookings', 'customer_verified_at')) {
                $table->dropColumn('customer_verified_at');
            }

            if (Schema::hasColumn('bookings', 'completion_requested_at')) {
                $table->dropColumn('completion_requested_at');
            }
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE `bookings` MODIFY `status` ENUM('requested','accepted','assigned','on_job','completed','rejected','cancelled') NOT NULL DEFAULT 'requested'");
        }
    }
};
