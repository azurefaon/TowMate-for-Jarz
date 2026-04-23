<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_number')->unique();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('truck_type_id')->constrained()->onDelete('cascade');
            
            // Service details
            $table->string('pickup_address');
            $table->string('dropoff_address');
            $table->text('pickup_notes')->nullable();
            $table->decimal('distance_km', 8, 2);
            
            // Vehicle details
            $table->string('vehicle_make')->nullable();
            $table->string('vehicle_model')->nullable();
            $table->string('vehicle_year')->nullable();
            $table->string('vehicle_color')->nullable();
            $table->string('vehicle_plate_number')->nullable();
            $table->string('vehicle_image_path')->nullable();
            
            // Pricing
            $table->decimal('estimated_price', 10, 2);
            $table->decimal('counter_offer_amount', 10, 2)->nullable();
            
            // Status tracking
            $table->enum('status', ['pending', 'sent', 'accepted', 'rejected', 'expired', 'disregarded'])->default('pending');
            
            // Timestamps
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->integer('expiry_hours')->default(168); // 1 week
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('follow_up_sent_at')->nullable();
            
            // Customer response
            $table->text('response_note')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('customer_id');
            $table->index('status');
            $table->index('expires_at');
            $table->index(['customer_id', 'status']);
        });
        
        // Add quotation_id to bookings table
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('quotation_id')->nullable()->after('id')->constrained()->onDelete('set null');
            $table->index('quotation_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['quotation_id']);
            $table->dropColumn('quotation_id');
        });
        
        Schema::dropIfExists('quotations');
    }
};
