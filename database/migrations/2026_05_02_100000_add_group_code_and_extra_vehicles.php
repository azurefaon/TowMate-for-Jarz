<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->json('extra_vehicles')->nullable()->after('vehicle_image_path');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('group_code', 30)->nullable()->index()->after('quotation_number');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn('extra_vehicles');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('group_code');
        });
    }
};
