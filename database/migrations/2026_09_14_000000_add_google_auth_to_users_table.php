<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('auth_provider')->default('password')->after('password');
            $table->string('google_sub')->nullable()->unique()->after('auth_provider');
        });

        DB::statement('ALTER TABLE users ALTER COLUMN password DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE users SET password = '' WHERE password IS NULL");
        DB::statement('ALTER TABLE users ALTER COLUMN password SET NOT NULL');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['auth_provider', 'google_sub']);
        });
    }
};
