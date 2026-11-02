<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's up-front payment preference (cash / gcash / bank_transfer).
 * Deliberately separate from bookings.payment_method, which stays the method
 * actually collected by the Team Leader at completion. Nullable: older
 * clients and existing rows simply have no preference. Quotations carry it
 * too because the web flow creates a quotation first and the booking only
 * when the customer accepts it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('preferred_payment_method', 20)->nullable()->after('payment_method');
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->string('preferred_payment_method', 20)->nullable()->after('service_type');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn('preferred_payment_method');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('preferred_payment_method');
        });
    }
};
