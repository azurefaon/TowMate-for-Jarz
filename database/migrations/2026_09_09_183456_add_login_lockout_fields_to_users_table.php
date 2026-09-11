<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('failed_login_attempts')->default(0)->after('last_login_at');
            $table->timestamp('last_failed_login_at')->nullable()->after('failed_login_attempts');
            $table->timestamp('locked_until')->nullable()->after('last_failed_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['failed_login_attempts', 'last_failed_login_at', 'locked_until']);
        });
    }
};
