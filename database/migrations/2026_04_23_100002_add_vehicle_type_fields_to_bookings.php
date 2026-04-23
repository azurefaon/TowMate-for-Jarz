<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('vehicle_type_id')->nullable()->after('truck_type_id')->constrained()->onDelete('set null');
            $table->string('customer_vehicle_type')->nullable()->after('vehicle_type_id');
            $table->enum('customer_vehicle_category', ['2_wheeler', '4_wheeler', 'heavy_vehicle'])->nullable()->after('customer_vehicle_type');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['vehicle_type_id']);
            $table->dropColumn(['vehicle_type_id', 'customer_vehicle_type', 'customer_vehicle_category']);
        });
    }
};
