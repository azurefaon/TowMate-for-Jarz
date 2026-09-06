<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Real, persistent Dispatcher notification feed with genuine read/unread
     * state — replaces the old topbar behavior that derived a pseudo-feed
     * (and a meaningless "count") directly from Booking status at render
     * time (see the removed View::composer('admin-dashboard.layouts.app')
     * block). Shared across all dispatchers (one operational queue, matching
     * how Incoming Requests/Active Jobs are already shared, not personal).
     */
    public function up(): void
    {
        Schema::create('dispatcher_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // new_book_now | new_scheduled | verification_required
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'booking_id']);
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatcher_notifications');
    }
};
