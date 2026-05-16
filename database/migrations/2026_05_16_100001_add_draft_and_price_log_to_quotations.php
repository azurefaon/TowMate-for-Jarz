<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL: alter column type to varchar so 'draft' can be stored
        DB::statement("ALTER TABLE quotations ALTER COLUMN status TYPE VARCHAR(30)");
        DB::statement("ALTER TABLE quotations ALTER COLUMN status SET DEFAULT 'pending'");

        Schema::table('quotations', function (Blueprint $table) {
            $table->json('price_change_log')->nullable()->after('response_note');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn('price_change_log');
        });

        DB::statement("ALTER TABLE quotations ALTER COLUMN status TYPE VARCHAR(30)");
        DB::statement("ALTER TABLE quotations ALTER COLUMN status SET DEFAULT 'pending'");
    }
};
