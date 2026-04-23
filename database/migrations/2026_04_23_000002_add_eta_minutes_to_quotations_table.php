<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('quotations', 'eta_minutes')) {
            Schema::table('quotations', function (Blueprint $table) {
                $table->decimal('eta_minutes', 6, 2)->nullable()->after('distance_km');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('quotations', 'eta_minutes')) {
            Schema::table('quotations', function (Blueprint $table) {
                $table->dropColumn('eta_minutes');
            });
        }
    }
};
