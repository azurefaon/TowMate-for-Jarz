<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The date whose booking_capacity counter currently includes this booking,
     * or NULL when the booking holds no counted slot. Acceptance sets it
     * together with the +1; cancellation, rejection and rescheduling use it to
     * release or move exactly that slot. It is the authoritative record, so a
     * reschedule or a repeated request can never decrement the wrong date or
     * decrement twice.
     *
     * Deliberately no backfill: bookings accepted before this column existed
     * stay NULL until a reconciled, approved correction sets them (see
     * `php artisan towmate:capacity-report`). Existing counters are not changed.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'capacity_reserved_date')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->date('capacity_reserved_date')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'capacity_reserved_date')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('capacity_reserved_date');
            });
        }
    }
};
