<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add scheduled_expires_at column to bookings
        if (! Schema::hasColumn('bookings', 'scheduled_expires_at')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->timestamp('scheduled_expires_at')->nullable()->after('scheduled_for');
            });
        }

        // 2. Add scheduled + scheduled_confirmed to status enum
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE `bookings` MODIFY `status` ENUM(
                'requested','reviewed','quoted','quotation_sent',
                'scheduled','scheduled_confirmed',
                'confirmed','accepted','assigned','on_the_way',
                'in_progress','waiting_verification','on_job',
                'rejected','completed','cancelled'
            ) NOT NULL DEFAULT 'requested'");
        }

        // 3. Create booking_capacity table for per-day slot limits
        if (! Schema::hasTable('booking_capacity')) {
            Schema::create('booking_capacity', function (Blueprint $table) {
                $table->id();
                $table->date('booking_date')->unique();
                $table->unsignedTinyInteger('slots_used')->default(0);
                $table->unsignedTinyInteger('slots_max')->default(2);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_capacity');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumnIfExists('scheduled_expires_at');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE `bookings` MODIFY `status` ENUM(
                'requested','reviewed','quoted','quotation_sent',
                'confirmed','accepted','assigned','on_the_way',
                'in_progress','waiting_verification','on_job',
                'rejected','completed','cancelled'
            ) NOT NULL DEFAULT 'requested'");
        }
    }
};
