<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('truck_types', function (Blueprint $table) {
            $table->enum('class', ['light', 'medium', 'heavy'])->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('truck_types', function (Blueprint $table) {
            $table->dropColumn('class');
        });
    }
};
