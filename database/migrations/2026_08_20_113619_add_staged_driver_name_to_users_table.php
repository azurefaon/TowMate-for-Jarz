<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('driver_first_name')->nullable()->after('duty_class');
            $table->string('driver_middle_name')->nullable()->after('driver_first_name');
            $table->string('driver_last_name')->nullable()->after('driver_middle_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['driver_first_name', 'driver_middle_name', 'driver_last_name']);
        });
    }
};
