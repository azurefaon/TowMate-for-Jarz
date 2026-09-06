<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'selected_unit_id')) {
                $table->foreignId('selected_unit_id')
                    ->nullable()
                    ->after('assigned_unit_id')
                    ->constrained('units')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'selected_unit_id')) {
                $table->dropConstrainedForeignId('selected_unit_id');
            }
        });
    }
};
