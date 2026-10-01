<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->foreignId('driver_seeded_by_team_leader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('crew_member_1_seeded_by_team_leader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('crew_member_2_seeded_by_team_leader_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('unit_crew_loans', function (Blueprint $table) {
            $table->foreignId('seeded_by_team_leader_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('unit_crew_loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seeded_by_team_leader_id');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_seeded_by_team_leader_id');
            $table->dropConstrainedForeignId('crew_member_1_seeded_by_team_leader_id');
            $table->dropConstrainedForeignId('crew_member_2_seeded_by_team_leader_id');
        });
    }
};
