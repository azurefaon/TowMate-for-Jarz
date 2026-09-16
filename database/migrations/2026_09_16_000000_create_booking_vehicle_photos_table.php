<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_vehicle_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->unsignedTinyInteger('vehicle_slot');
            $table->string('path');
            $table->timestamps();

            $table->index(['booking_id', 'vehicle_slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_vehicle_photos');
    }
};
