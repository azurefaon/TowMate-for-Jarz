<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dedicated OTP table for the WEB STAFF (Dispatcher/Team Leader) password
     * recovery flow. Deliberately separate from the Customer mobile app's
     * plaintext users.password_reset_otp* columns (Api\PasswordResetController)
     * so this hardened flow can never regress or be confused with that one.
     * One row per email — a new OTP always overwrites (invalidates) the prior row.
     */
    public function up(): void
    {
        Schema::create('staff_password_reset_otps', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('otp_hash');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_password_reset_otps');
    }
};
