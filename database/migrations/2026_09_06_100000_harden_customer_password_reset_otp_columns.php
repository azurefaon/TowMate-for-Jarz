<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces the CUSTOMER mobile app's plaintext password-reset OTP/token
     * columns (Api\PasswordResetController) with hashed equivalents plus
     * server-authoritative attempt/cooldown tracking, mirroring the design
     * already used by staff_password_reset_otps for the web staff flow.
     *
     * password_reset_otp / password_reset_token held only short-lived,
     * already-consumed-or-expired secrets — there is no meaningful data to
     * preserve across this migration, so a straight drop-and-replace is safe.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password_reset_otp', 'password_reset_token']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password_reset_otp_hash')->nullable()->after('password_reset_otp_expires_at');
            $table->unsignedTinyInteger('password_reset_attempts')->default(0)->after('password_reset_otp_hash');
            $table->timestamp('password_reset_resend_available_at')->nullable()->after('password_reset_attempts');
            $table->string('password_reset_token_hash')->nullable()->after('password_reset_resend_available_at');
            $table->timestamp('password_reset_token_expires_at')->nullable()->after('password_reset_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'password_reset_otp_hash',
                'password_reset_attempts',
                'password_reset_resend_available_at',
                'password_reset_token_hash',
                'password_reset_token_expires_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password_reset_otp', 6)->nullable()->after('password');
            $table->string('password_reset_token', 64)->nullable()->after('password_reset_otp_expires_at');
        });
    }
};
