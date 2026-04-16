<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'returned_at')) {
                $table->timestamp('returned_at')->nullable()->after('completed_at');
            }

            if (! Schema::hasColumn('bookings', 'return_reason')) {
                $table->text('return_reason')->nullable()->after('returned_at');
            }

            if (! Schema::hasColumn('bookings', 'returned_by_team_leader_id')) {
                $table->foreignId('returned_by_team_leader_id')->nullable()->after('return_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            foreach (['returned_by_team_leader_id', 'return_reason', 'returned_at'] as $column) {
                if (Schema::hasColumn('bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
