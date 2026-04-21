<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'quotation_status')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('quotation_status')->nullable()->after('quotation_generated');
            });
        }

        DB::table('bookings')
            ->whereNull('quotation_status')
            ->update(['quotation_status' => 'active']);

        DB::table('bookings')
            ->whereIn('status', ['cancelled', 'rejected'])
            ->update(['quotation_status' => 'cancelled']);

        DB::table('bookings')
            ->whereIn('status', ['confirmed', 'accepted', 'assigned', 'on_the_way', 'in_progress', 'waiting_verification', 'completed', 'on_job'])
            ->update(['quotation_status' => 'accepted']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'quotation_status')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('quotation_status');
            });
        }
    }
};
