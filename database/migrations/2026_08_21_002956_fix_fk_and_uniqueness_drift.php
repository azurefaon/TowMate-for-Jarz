<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();

            $table->dropForeign(['truck_type_id']);
            $table->foreign('truck_type_id')->references('id')->on('truck_types')->restrictOnDelete();
        });

        Schema::table('zones', function (Blueprint $table) {
            $table->unique('name');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreign('returned_by_team_leader_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['returned_by_team_leader_id']);
        });

        Schema::table('zones', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropForeign(['truck_type_id']);
            $table->foreign('truck_type_id')->references('id')->on('truck_types')->cascadeOnDelete();

            $table->dropForeign(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });
    }
};
