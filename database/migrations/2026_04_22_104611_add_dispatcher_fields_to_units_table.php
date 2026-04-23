<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->string('dispatcher_status')->nullable()->after('status');
            $table->boolean('zone_confirmed')->default(false)->after('zone_id');
            $table->string('dispatcher_note', 120)->nullable()->after('zone_confirmed');
            $table->string('last_updated_by')->nullable()->after('dispatcher_note');
            $table->timestamp('last_updated_at')->nullable()->after('last_updated_by');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn(['dispatcher_status', 'zone_confirmed', 'dispatcher_note', 'last_updated_by', 'last_updated_at']);
        });
    }
};
