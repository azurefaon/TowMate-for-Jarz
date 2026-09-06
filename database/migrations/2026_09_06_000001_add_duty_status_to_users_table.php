<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Duty is a new, separate concept from Presence (last_ping_at, mobile
     * heartbeat) and from Workload (derived live from active Booking status).
     * It belongs on the Team Leader's own User row because a Team Leader is
     * always a real, addressable account with a stable identity that must
     * follow them across units when borrowed — unlike Driver/Crew, which are
     * usually free-text Unit-slot values (see units.driver_duty_status /
     * crew_*_duty_status instead). Nullable, with null meaning "available"
     * (the existing operational assumption), so no backfill is required and
     * no existing behavior changes until a Dispatcher explicitly sets it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('duty_status')->nullable()->after('last_ping_at');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement(
                "ALTER TABLE users ADD CONSTRAINT users_duty_status_check CHECK (duty_status IN ('available','unavailable'))"
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_duty_status_check');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('duty_status');
        });
    }
};
