<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'service_type')) {
                $table->string('service_type')->default('book_now');
            }

            if (! Schema::hasColumn('bookings', 'scheduled_date')) {
                $table->date('scheduled_date')->nullable();
            }

            if (! Schema::hasColumn('bookings', 'scheduled_time')) {
                $table->string('scheduled_time', 10)->nullable();
            }

            if (! Schema::hasColumn('bookings', 'scheduled_for')) {
                $table->timestamp('scheduled_for')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'scheduled_for')) {
                $table->dropIndex(['scheduled_for']);
                $table->dropColumn('scheduled_for');
            }

            if (Schema::hasColumn('bookings', 'scheduled_time')) {
                $table->dropColumn('scheduled_time');
            }

            if (Schema::hasColumn('bookings', 'scheduled_date')) {
                $table->dropColumn('scheduled_date');
            }

            if (Schema::hasColumn('bookings', 'service_type')) {
                $table->dropColumn('service_type');
            }
        });
    }
};
