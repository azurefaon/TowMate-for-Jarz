<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('paymongo_intent_id')->nullable()->after('paymongo_checkout_url');
            $table->string('paymongo_client_key')->nullable()->after('paymongo_intent_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['paymongo_intent_id', 'paymongo_client_key']);
        });
    }
};
