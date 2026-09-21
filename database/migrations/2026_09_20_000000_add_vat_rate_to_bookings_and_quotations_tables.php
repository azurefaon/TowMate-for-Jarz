<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'vat_rate')) {
                $table->decimal('vat_rate', 6, 4)->nullable()->after('vat_exclusive_total');
            }
        });

        Schema::table('quotations', function (Blueprint $table) {
            if (! Schema::hasColumn('quotations', 'vat_rate')) {
                $table->decimal('vat_rate', 6, 4)->nullable()->after('discount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'vat_rate')) {
                $table->dropColumn('vat_rate');
            }
        });

        Schema::table('quotations', function (Blueprint $table) {
            if (Schema::hasColumn('quotations', 'vat_rate')) {
                $table->dropColumn('vat_rate');
            }
        });
    }
};
