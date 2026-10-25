<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Next-task queue offers.
     *
     * An offer reserves ONE not-yet-assigned booking for a Team Leader who is finishing another job.
     * It is deliberately separate from bookings: a queued booking keeps its own status and has no
     * assigned unit or Team Leader, so it can never count as an active assignment.
     *
     * "Active" means offered or accepted. Partial unique indexes allow at most one active offer per
     * Team Leader, per unit and per booking (and per job being finished), while rejected, expired,
     * cancelled and promoted rows stay as history and never block a new offer.
     */
    public function up(): void
    {
        Schema::create('booking_queue_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('behind_booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('team_leader_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->string('status', 16)->default('offered');
            $table->timestamp('offered_at');
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index('booking_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE booking_queue_offers ADD CONSTRAINT booking_queue_offers_status_check CHECK (status IN ('offered','accepted','rejected','expired','cancelled','promoted'))");

            foreach (['booking_id', 'behind_booking_id', 'team_leader_id', 'unit_id'] as $column) {
                DB::statement("CREATE UNIQUE INDEX booking_queue_offers_active_{$column}_unique ON booking_queue_offers ({$column}) WHERE status IN ('offered','accepted')");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_queue_offers');
    }
};
