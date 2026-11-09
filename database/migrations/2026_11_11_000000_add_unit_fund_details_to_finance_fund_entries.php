<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner Finance Phase 2 (Unit Funds).
 *
 * - issue_kind: initial vs additional issuance (at most one recorded initial issuance per unit).
 * - external_reference: voucher or receipt number written on the paper record.
 * - corrects_entry_id: a correction is a new entry that points at the voided original, so history stays traceable.
 *
 * The new columns join the immutable set guarded by the Phase 1 trigger.
 */
return new class extends Migration
{
    private const CORE_COLUMNS = "'reference_code','idempotency_key','entry_type','unit_id','counterparty_name','amount','entry_date','issuance_id','notes','created_at'";

    public function up(): void
    {
        Schema::table('finance_fund_entries', function (Blueprint $table) {
            $table->string('issue_kind', 12)->nullable()->after('entry_type');          // initial | additional (issuances only)
            $table->string('external_reference', 100)->nullable()->after('counterparty_name');
            $table->foreignId('corrects_entry_id')->nullable()->after('issuance_id')
                ->constrained('finance_fund_entries')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE finance_fund_entries ADD CONSTRAINT ffe_issue_kind CHECK (
            (entry_type = 'issuance' AND issue_kind IN ('initial', 'additional'))
            OR (entry_type = 'return' AND issue_kind IS NULL))");
        DB::statement('ALTER TABLE finance_fund_entries ADD CONSTRAINT ffe_not_self_correction CHECK (corrects_entry_id IS NULL OR corrects_entry_id <> id)');
        DB::statement("CREATE UNIQUE INDEX ffe_one_initial_per_unit ON finance_fund_entries (unit_id) WHERE issue_kind = 'initial' AND status = 'recorded'");
        DB::statement("CREATE UNIQUE INDEX ffe_one_correction_per_entry ON finance_fund_entries (corrects_entry_id) WHERE corrects_entry_id IS NOT NULL AND status = 'recorded'");

        DB::statement('DROP TRIGGER IF EXISTS ffe_core ON finance_fund_entries');
        DB::statement('CREATE TRIGGER ffe_core BEFORE UPDATE ON finance_fund_entries FOR EACH ROW EXECUTE FUNCTION finance_guard_columns('
            . self::CORE_COLUMNS . ",'issue_kind','external_reference','corrects_entry_id')");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS ffe_core ON finance_fund_entries');
            DB::statement('CREATE TRIGGER ffe_core BEFORE UPDATE ON finance_fund_entries FOR EACH ROW EXECUTE FUNCTION finance_guard_columns(' . self::CORE_COLUMNS . ')');
            DB::statement('DROP INDEX IF EXISTS ffe_one_initial_per_unit');
            DB::statement('DROP INDEX IF EXISTS ffe_one_correction_per_entry');
            DB::statement('ALTER TABLE finance_fund_entries DROP CONSTRAINT IF EXISTS ffe_issue_kind');
            DB::statement('ALTER TABLE finance_fund_entries DROP CONSTRAINT IF EXISTS ffe_not_self_correction');
        }

        Schema::table('finance_fund_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('corrects_entry_id');
            $table->dropColumn(['issue_kind', 'external_reference']);
        });
    }
};
