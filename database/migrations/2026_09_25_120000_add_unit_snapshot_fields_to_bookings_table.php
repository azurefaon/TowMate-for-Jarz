<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'assigned_unit_name')) {
                $table->string('assigned_unit_name')->nullable()->after('assigned_unit_id');
            }
            if (! Schema::hasColumn('bookings', 'assigned_unit_plate_number')) {
                $table->string('assigned_unit_plate_number')->nullable()->after('assigned_unit_name');
            }
            if (! Schema::hasColumn('bookings', 'selected_unit_name')) {
                $table->string('selected_unit_name')->nullable()->after('selected_unit_id');
            }
            if (! Schema::hasColumn('bookings', 'selected_unit_plate_number')) {
                $table->string('selected_unit_plate_number')->nullable()->after('selected_unit_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            foreach (['assigned_unit_name', 'assigned_unit_plate_number', 'selected_unit_name', 'selected_unit_plate_number'] as $column) {
                if (Schema::hasColumn('bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
