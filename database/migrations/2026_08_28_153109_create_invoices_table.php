<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->foreignId('booking_id')->constrained()->onDelete('cascade');
            $table->foreignId('quotation_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('previous_invoice_id')->nullable()->constrained('invoices')->onDelete('set null');
            $table->foreignId('original_invoice_id')->nullable()->constrained('invoices')->onDelete('set null');

            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('additional_fee', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);

            $table->string('status')->default('issued'); // issued, voided
            $table->boolean('is_current')->default(true);
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();

            $table->text('pdf_path')->nullable();
            $table->boolean('email_sent')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');

            $table->timestamps();

            $table->index(['booking_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
