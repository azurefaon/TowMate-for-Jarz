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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('truck_type_id')->constrained();
            $table->foreignId('assigned_unit_id')->nullable()->constrained('units');
            $table->foreignId('created_by_admin_id')->constrained('users');

            $table->text('pickup_address');
            $table->decimal('pickup_lat', 10, 7)->nullable();
            $table->decimal('pickup_lng', 10, 7)->nullable();

            $table->text('dropoff_address');
            $table->decimal('dropoff_lat', 10, 7)->nullable();
            $table->decimal('dropoff_lng', 10, 7)->nullable();

            $table->decimal('distance_km', 10, 2)->nullable();
            $table->decimal('base_rate', 10, 2)->nullable();
            $table->decimal('per_km_rate', 10, 2)->nullable();
            $table->decimal('computed_total', 10, 2)->nullable();
            $table->decimal('discount_percentage', 5, 2)->nullable();
            $table->string('discount_reason')->nullable();
            $table->decimal('final_total', 10, 2)->nullable();

            $table->enum('status', ['requested','assigned','on_job','completed','cancelled'])
                ->default('requested');

            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
