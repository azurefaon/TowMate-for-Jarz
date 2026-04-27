<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('paymongo_link_id')->nullable()->after('payment_submitted_at');
            $table->text('paymongo_checkout_url')->nullable()->after('paymongo_link_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['paymongo_link_id', 'paymongo_checkout_url']);
        });
    }
};
