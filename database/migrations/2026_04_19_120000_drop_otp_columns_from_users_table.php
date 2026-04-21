<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = array_values(array_filter([
            Schema::hasColumn('users', 'otp_code') ? 'otp_code' : null,
            Schema::hasColumn('users', 'otp_plain_code') ? 'otp_plain_code' : null,
            Schema::hasColumn('users', 'otp_expires_at') ? 'otp_expires_at' : null,
            Schema::hasColumn('users', 'otp_attempts') ? 'otp_attempts' : null,
            Schema::hasColumn('users', 'otp_last_sent_at') ? 'otp_last_sent_at' : null,
        ]));

        if ($columns !== []) {
            Schema::table('users', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'otp_code')) {
                $table->string('otp_code')->nullable();
            }

            if (! Schema::hasColumn('users', 'otp_plain_code')) {
                $table->string('otp_plain_code')->nullable();
            }

            if (! Schema::hasColumn('users', 'otp_expires_at')) {
                $table->timestamp('otp_expires_at')->nullable();
            }

            if (! Schema::hasColumn('users', 'otp_attempts')) {
                $table->integer('otp_attempts')->default(0);
            }

            if (! Schema::hasColumn('users', 'otp_last_sent_at')) {
                $table->timestamp('otp_last_sent_at')->nullable();
            }
        });
    }
};
