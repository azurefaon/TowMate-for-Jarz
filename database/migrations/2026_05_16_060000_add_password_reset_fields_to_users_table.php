<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password_reset_otp', 6)->nullable()->after('password');
            $table->timestamp('password_reset_otp_expires_at')->nullable()->after('password_reset_otp');
            $table->string('password_reset_token', 64)->nullable()->after('password_reset_otp_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password_reset_otp', 'password_reset_otp_expires_at', 'password_reset_token']);
        });
    }
};
