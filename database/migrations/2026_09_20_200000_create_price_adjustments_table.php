<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_number');
            $table->string('type');
            $table->decimal('amount', 10, 2);
            $table->text('reason')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reverted_at')->nullable();
            $table->foreignId('reverted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['quotation_number', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_adjustments');
    }
};
