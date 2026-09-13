<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'home_unit_id')) {
                $table->foreignId('home_unit_id')
                    ->nullable()
                    ->after('role_id')
                    ->constrained('units')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'home_unit_id')) {
                $table->dropConstrainedForeignId('home_unit_id');
            }
        });
    }
};
