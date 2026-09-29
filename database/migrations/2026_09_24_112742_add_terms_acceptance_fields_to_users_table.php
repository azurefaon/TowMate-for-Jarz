<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('terms_version')->nullable()->after('email_verified_at');
            $table->string('privacy_version')->nullable()->after('terms_version');
            $table->timestamp('terms_accepted_at')->nullable()->after('privacy_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['terms_version', 'privacy_version', 'terms_accepted_at']);
        });
    }
};
