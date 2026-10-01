<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->foreignId('driver_personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->foreignId('driver_2_personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->foreignId('crew_member_1_personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->foreignId('crew_member_2_personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('driver_personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->foreignId('crew_member_1_personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->foreignId('crew_member_2_personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
        });

        Schema::table('unit_crew_loans', function (Blueprint $table) {
            $table->foreignId('personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('unit_crew_loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('personnel_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_personnel_id');
            $table->dropConstrainedForeignId('crew_member_1_personnel_id');
            $table->dropConstrainedForeignId('crew_member_2_personnel_id');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_personnel_id');
            $table->dropConstrainedForeignId('driver_2_personnel_id');
            $table->dropConstrainedForeignId('crew_member_1_personnel_id');
            $table->dropConstrainedForeignId('crew_member_2_personnel_id');
        });
    }
};
