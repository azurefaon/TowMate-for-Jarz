<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('vehicle_type_truck_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_type_id')->constrained()->onDelete('cascade');
            $table->foreignId('truck_type_id')->constrained()->onDelete('cascade');
            $table->timestamps();
            
            $table->unique(['vehicle_type_id', 'truck_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_type_truck_type');
    }
};
