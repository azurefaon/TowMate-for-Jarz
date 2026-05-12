<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('completion_otp', 6)->nullable()->after('customer_verification_note');
            $table->timestamp('completion_otp_expires_at')->nullable()->after('completion_otp');
            $table->string('arrival_photo_path')->nullable()->after('completion_otp_expires_at');
            $table->string('dropoff_photo_path')->nullable()->after('arrival_photo_path');
            $table->string('customer_signature_path')->nullable()->after('dropoff_photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'completion_otp',
                'completion_otp_expires_at',
                'arrival_photo_path',
                'dropoff_photo_path',
                'customer_signature_path',
            ]);
        });
    }
};
