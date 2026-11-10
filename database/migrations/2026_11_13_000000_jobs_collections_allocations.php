<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner Finance Phase 4 (Jobs & Collections).
 *
 * finance_revenue_allocations can now hold an Owner-entered split between towing units ('manual_split') for a
 * booking that more than one unit worked on. allocatable_total records the verified collection the split was made
 * against, so a later payment change marks the split as needing review instead of silently mis-allocating.
 * Automatic allocations (each vehicle to its own assigned unit) are computed on the fly and are not stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_revenue_allocations', function (Blueprint $table) {
            $table->decimal('allocatable_total', 12, 2)->nullable()->after('net_amount');
            $table->text('notes')->nullable()->after('allocation_method');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE finance_revenue_allocations DROP CONSTRAINT IF EXISTS fra_method');
        DB::statement("ALTER TABLE finance_revenue_allocations ADD CONSTRAINT fra_method CHECK (allocation_method IN ('single_booking', 'group_share', 'manual_split'))");
        DB::statement("ALTER TABLE finance_revenue_allocations ADD CONSTRAINT fra_manual_basis CHECK (allocation_method <> 'manual_split' OR (allocatable_total IS NOT NULL AND allocatable_total > 0 AND gross_amount > 0))");

        DB::statement('DROP TRIGGER IF EXISTS fra_core ON finance_revenue_allocations');
        DB::statement("CREATE TRIGGER fra_core BEFORE UPDATE ON finance_revenue_allocations FOR EACH ROW EXECUTE FUNCTION finance_guard_columns('booking_id','unit_id','gross_amount','vat_amount','net_amount','allocation_method','collected_at','created_at','allocatable_total','notes')");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS fra_core ON finance_revenue_allocations');
            DB::statement("CREATE TRIGGER fra_core BEFORE UPDATE ON finance_revenue_allocations FOR EACH ROW EXECUTE FUNCTION finance_guard_columns('booking_id','unit_id','gross_amount','vat_amount','net_amount','allocation_method','collected_at','created_at')");
            DB::statement('ALTER TABLE finance_revenue_allocations DROP CONSTRAINT IF EXISTS fra_manual_basis');
            DB::statement('ALTER TABLE finance_revenue_allocations DROP CONSTRAINT IF EXISTS fra_method');
            DB::statement("ALTER TABLE finance_revenue_allocations ADD CONSTRAINT fra_method CHECK (allocation_method IN ('single_booking', 'group_share')) NOT VALID");
        }

        Schema::table('finance_revenue_allocations', function (Blueprint $table) {
            $table->dropColumn(['allocatable_total', 'notes']);
        });
    }
};
