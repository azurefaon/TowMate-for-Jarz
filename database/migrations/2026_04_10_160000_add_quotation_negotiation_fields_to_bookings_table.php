<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'dispatcher_note')) {
                $table->text('dispatcher_note')->nullable()->after('quotation_generated');
            }

            if (! Schema::hasColumn('bookings', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('rejection_reason');
            }

            if (! Schema::hasColumn('bookings', 'quoted_at')) {
                $table->timestamp('quoted_at')->nullable()->after('reviewed_at');
            }

            if (! Schema::hasColumn('bookings', 'negotiation_requested_at')) {
                $table->timestamp('negotiation_requested_at')->nullable()->after('quoted_at');
            }

            if (! Schema::hasColumn('bookings', 'counter_offer_amount')) {
                $table->decimal('counter_offer_amount', 10, 2)->nullable()->after('negotiation_requested_at');
            }

            if (! Schema::hasColumn('bookings', 'customer_response_note')) {
                $table->text('customer_response_note')->nullable()->after('counter_offer_amount');
            }

            if (! Schema::hasColumn('bookings', 'customer_approved_at')) {
                $table->timestamp('customer_approved_at')->nullable()->after('customer_response_note');
            }
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE `bookings` MODIFY `status` ENUM('requested','reviewed','quoted','quotation_sent','confirmed','accepted','assigned','on_the_way','in_progress','waiting_verification','on_job','rejected','completed','cancelled') NOT NULL DEFAULT 'requested'");
        }
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'customer_approved_at')) {
                $table->dropColumn('customer_approved_at');
            }

            if (Schema::hasColumn('bookings', 'customer_response_note')) {
                $table->dropColumn('customer_response_note');
            }

            if (Schema::hasColumn('bookings', 'counter_offer_amount')) {
                $table->dropColumn('counter_offer_amount');
            }

            if (Schema::hasColumn('bookings', 'negotiation_requested_at')) {
                $table->dropColumn('negotiation_requested_at');
            }

            if (Schema::hasColumn('bookings', 'quoted_at')) {
                $table->dropColumn('quoted_at');
            }

            if (Schema::hasColumn('bookings', 'reviewed_at')) {
                $table->dropColumn('reviewed_at');
            }

            if (Schema::hasColumn('bookings', 'dispatcher_note')) {
                $table->dropColumn('dispatcher_note');
            }
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE `bookings` MODIFY `status` ENUM('requested','accepted','assigned','on_the_way','in_progress','waiting_verification','on_job','rejected','completed','cancelled') NOT NULL DEFAULT 'requested'");
        }
    }
};
