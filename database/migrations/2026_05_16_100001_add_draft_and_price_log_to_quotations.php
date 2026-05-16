<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Change status column from enum to varchar so 'draft' can be added without DB-level enum changes
        DB::statement("ALTER TABLE quotations MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'pending'");

        Schema::table('quotations', function (Blueprint $table) {
            $table->json('price_change_log')->nullable()->after('response_note');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn('price_change_log');
        });

        // Restore enum (without draft)
        DB::statement("ALTER TABLE quotations MODIFY COLUMN status ENUM('pending','sent','accepted','rejected','expired','disregarded') NOT NULL DEFAULT 'pending'");
    }
};
