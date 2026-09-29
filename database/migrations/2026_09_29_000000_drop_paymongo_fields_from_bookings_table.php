<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'paymongo_link_id',
                'paymongo_checkout_url',
                'paymongo_intent_id',
                'paymongo_client_key',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('paymongo_link_id')->nullable();
            $table->text('paymongo_checkout_url')->nullable();
            $table->string('paymongo_intent_id')->nullable();
            $table->string('paymongo_client_key')->nullable();
        });
    }
};
