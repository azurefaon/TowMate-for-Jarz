<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('quotation_number');
            $table->boolean('is_current')->default(true)->after('version');
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropUnique(['quotation_number']);
            $table->unique(['quotation_number', 'version']);
            $table->index(['quotation_number', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropIndex(['quotation_number', 'is_current']);
            $table->dropUnique(['quotation_number', 'version']);
            $table->unique('quotation_number');
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['version', 'is_current']);
        });
    }
};
