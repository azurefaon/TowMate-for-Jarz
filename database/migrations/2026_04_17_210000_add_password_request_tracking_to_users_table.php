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
            $table->string('password_request_status')->default('none')->after('status');
            $table->timestamp('password_requested_at')->nullable()->after('password_request_status');
            $table->text('password_request_note')->nullable()->after('password_requested_at');
            $table->timestamp('password_request_resolved_at')->nullable()->after('password_request_note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'password_request_status',
                'password_requested_at',
                'password_request_note',
                'password_request_resolved_at',
            ]);
        });
    }
};
