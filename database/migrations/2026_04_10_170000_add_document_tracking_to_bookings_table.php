<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'initial_quote_path')) {
                $table->string('initial_quote_path')->nullable()->after('quotation_number');
            }

            if (! Schema::hasColumn('bookings', 'final_quote_path')) {
                $table->string('final_quote_path')->nullable()->after('initial_quote_path');
            }

            if (! Schema::hasColumn('bookings', 'quotation_sent_at')) {
                $table->timestamp('quotation_sent_at')->nullable()->after('quoted_at');
            }

            if (! Schema::hasColumn('bookings', 'price_locked_at')) {
                $table->timestamp('price_locked_at')->nullable()->after('customer_approved_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            foreach (['initial_quote_path', 'final_quote_path', 'quotation_sent_at', 'price_locked_at'] as $column) {
                if (Schema::hasColumn('bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
