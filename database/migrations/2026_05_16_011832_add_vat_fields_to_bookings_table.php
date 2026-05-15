<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('vat_amount', 10, 2)->nullable()->after('final_total');
            $table->decimal('vat_exclusive_total', 10, 2)->nullable()->after('vat_amount');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['vat_amount', 'vat_exclusive_total']);
        });
    }
};
