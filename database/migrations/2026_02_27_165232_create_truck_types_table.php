<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('truck_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('base_rate', 10, 2);
            $table->decimal('per_km_rate', 10, 2);
            $table->decimal('max_tonnage', 10, 2)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('truck_types');
    }
};
