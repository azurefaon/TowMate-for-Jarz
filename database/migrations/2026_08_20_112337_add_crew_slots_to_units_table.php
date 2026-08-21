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
        Schema::table('units', function (Blueprint $table) {
            $table->string('driver_2_name')->nullable()->after('driver_name');
            $table->string('crew_member_1_name')->nullable()->after('driver_2_name');
            $table->string('crew_member_2_name')->nullable()->after('crew_member_1_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn(['driver_2_name', 'crew_member_1_name', 'crew_member_2_name']);
        });
    }
};
