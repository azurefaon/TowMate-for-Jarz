<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE `bookings` MODIFY `status` ENUM('requested','reviewed','quoted','quotation_sent','confirmed','accepted','assigned','on_the_way','in_progress','waiting_verification','on_job','rejected','completed','cancelled') NOT NULL DEFAULT 'requested'");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE `bookings` MODIFY `status` ENUM('requested','reviewed','quoted','confirmed','accepted','assigned','on_the_way','in_progress','waiting_verification','on_job','rejected','completed','cancelled') NOT NULL DEFAULT 'requested'");
        }
    }
};
