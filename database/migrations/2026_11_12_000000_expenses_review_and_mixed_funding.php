<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner Finance Phase 3 (Expenses and receipt review).
 *
 * 1. finance_expense_fundings: how ONE expense was paid. A single expense can be split between issued unit
 *    funds, a direct company payment and a personal advance. The splits must sum exactly to the expense amount,
 *    so the cost is recorded once, and only the unit_fund split ever reduces a unit fund balance.
 * 2. finance_expenses: job vs unit/monthly scope, coverage month, a shortfall snapshot (unit-fund spending
 *    above the cash available at entry; recorded as a warning, never as Team Leader debt), and a correction link.
 *    funding_source becomes the summary of the splits: unit_fund | company_direct | personal_advance | mixed.
 * 3. Approval requires at least one evidence file AND splits that sum exactly to the amount (DB trigger).
 * 4. Phase 2 correction: units receive operating funds per day, so the lifetime "one initial issuance per unit"
 *    index is dropped. Existing rows keep their values; only the restriction is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_expense_fundings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('finance_expenses')->restrictOnDelete();
            $table->string('source', 20);                                     // unit_fund | company_direct | personal_advance
            $table->decimal('amount', 12, 2);
            $table->foreignId('fund_entry_id')->nullable()->constrained('finance_fund_entries')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['expense_id', 'source']);
            $table->index('source');
        });

        Schema::table('finance_expenses', function (Blueprint $table) {
            $table->string('expense_scope', 10)->default('job')->after('booking_id');   // job | unit
            $table->date('coverage_month')->nullable()->after('expense_date');         // unit/monthly expenses: first day of the month covered
            $table->decimal('unit_fund_shortfall', 12, 2)->default(0)->after('funding_source');
            $table->foreignId('corrects_expense_id')->nullable()->after('fund_entry_id')
                ->constrained('finance_expenses')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Phase 2 correction: daily issuances are normal.
        DB::statement('DROP INDEX IF EXISTS ffe_one_initial_per_unit');

        DB::statement('ALTER TABLE finance_expenses DROP CONSTRAINT IF EXISTS fex_funding');
        DB::statement("ALTER TABLE finance_expenses ADD CONSTRAINT fex_funding CHECK (funding_source IN ('unit_fund', 'company_direct', 'personal_advance', 'mixed'))");
        DB::statement("ALTER TABLE finance_expenses ADD CONSTRAINT fex_scope CHECK (
            (expense_scope = 'job' AND booking_id IS NOT NULL AND coverage_month IS NULL)
            OR (expense_scope = 'unit' AND booking_id IS NULL AND coverage_month IS NOT NULL AND EXTRACT(DAY FROM coverage_month) = 1)) NOT VALID");
        DB::statement('ALTER TABLE finance_expenses ADD CONSTRAINT fex_shortfall CHECK (unit_fund_shortfall >= 0 AND unit_fund_shortfall <= amount)');
        DB::statement('ALTER TABLE finance_expenses ADD CONSTRAINT fex_not_self_correction CHECK (corrects_expense_id IS NULL OR corrects_expense_id <> id)');
        DB::statement("CREATE UNIQUE INDEX fex_one_open_correction ON finance_expenses (corrects_expense_id) WHERE corrects_expense_id IS NOT NULL AND status NOT IN ('rejected', 'voided')");

        DB::statement("ALTER TABLE finance_expense_fundings ADD CONSTRAINT fef_source CHECK (source IN ('unit_fund', 'company_direct', 'personal_advance'))");
        DB::statement('ALTER TABLE finance_expense_fundings ADD CONSTRAINT fef_amount CHECK (amount > 0)');
        DB::statement("ALTER TABLE finance_expense_fundings ADD CONSTRAINT fef_fund_link CHECK (fund_entry_id IS NULL OR source = 'unit_fund')");

        // The new expense columns join the locked sets.
        DB::statement('DROP TRIGGER IF EXISTS fex_core ON finance_expenses');
        DB::statement("CREATE TRIGGER fex_core BEFORE UPDATE ON finance_expenses FOR EACH ROW EXECUTE FUNCTION finance_guard_columns('reference_code','idempotency_key','submitted_at','created_at','corrects_expense_id','unit_fund_shortfall')");
        DB::statement('DROP TRIGGER IF EXISTS fex_details ON finance_expenses');
        DB::statement("CREATE TRIGGER fex_details BEFORE UPDATE ON finance_expenses FOR EACH ROW WHEN (OLD.status <> 'submitted') EXECUTE FUNCTION finance_guard_columns('unit_id','booking_id','fund_entry_id','category','description','amount','expense_date','funding_source','expense_scope','coverage_month')");

        DB::unprepared(<<<'SQL'
-- Approval needs evidence, and funding splits that add up exactly to the amount.
CREATE OR REPLACE FUNCTION finance_expense_approval_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    funded numeric;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM finance_expense_evidence WHERE expense_id = NEW.id) THEN
        RAISE EXCEPTION 'An expense cannot be approved without a receipt or payment evidence.' USING ERRCODE = 'P0001';
    END IF;
    SELECT COALESCE(SUM(amount), 0) INTO funded FROM finance_expense_fundings WHERE expense_id = NEW.id;
    IF funded <> NEW.amount THEN
        RAISE EXCEPTION 'Expense funding (%) does not equal the expense amount (%).', funded, NEW.amount USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$;

-- Funding splits can only change while the expense is still a fresh submission.
CREATE OR REPLACE FUNCTION finance_fundings_require_open_expense() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    st text;
BEGIN
    SELECT status INTO st FROM finance_expenses
        WHERE id = (CASE WHEN TG_OP = 'DELETE' THEN OLD.expense_id ELSE NEW.expense_id END);
    IF st IS DISTINCT FROM 'submitted' THEN
        RAISE EXCEPTION 'Funding for an expense is locked once it is %.', st USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.expense_id IS DISTINCT FROM NEW.expense_id THEN
        RAISE EXCEPTION 'A funding split cannot move to another expense.' USING ERRCODE = 'P0001';
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;
SQL);

        DB::statement("CREATE TRIGGER fex_approval_guard BEFORE UPDATE ON finance_expenses FOR EACH ROW WHEN (NEW.status = 'approved' AND OLD.status <> 'approved') EXECUTE FUNCTION finance_expense_approval_guard()");
        DB::statement('CREATE TRIGGER fef_open_expense BEFORE INSERT OR UPDATE OR DELETE ON finance_expense_fundings FOR EACH ROW EXECUTE FUNCTION finance_fundings_require_open_expense()');
    }

    public function down(): void
    {
        // Development rollback only. Never run against a database that holds real finance records.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS fex_approval_guard ON finance_expenses');
            DB::statement('DROP FUNCTION IF EXISTS finance_expense_approval_guard()');
            DB::statement('DROP TRIGGER IF EXISTS fex_core ON finance_expenses');
            DB::statement("CREATE TRIGGER fex_core BEFORE UPDATE ON finance_expenses FOR EACH ROW EXECUTE FUNCTION finance_guard_columns('reference_code','idempotency_key','submitted_at','created_at')");
            DB::statement('DROP TRIGGER IF EXISTS fex_details ON finance_expenses');
            DB::statement("CREATE TRIGGER fex_details BEFORE UPDATE ON finance_expenses FOR EACH ROW WHEN (OLD.status <> 'submitted') EXECUTE FUNCTION finance_guard_columns('unit_id','booking_id','fund_entry_id','category','description','amount','expense_date','funding_source')");
            DB::statement('DROP INDEX IF EXISTS fex_one_open_correction');
            foreach (['fex_scope', 'fex_shortfall', 'fex_not_self_correction', 'fex_funding'] as $constraint) {
                DB::statement("ALTER TABLE finance_expenses DROP CONSTRAINT IF EXISTS {$constraint}");
            }
            DB::statement("ALTER TABLE finance_expenses ADD CONSTRAINT fex_funding CHECK (funding_source IN ('unit_fund', 'company_direct', 'crew_out_of_pocket')) NOT VALID");
        }

        Schema::dropIfExists('finance_expense_fundings');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS finance_fundings_require_open_expense()');
        }

        Schema::table('finance_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('corrects_expense_id');
            $table->dropColumn(['expense_scope', 'coverage_month', 'unit_fund_shortfall']);
        });
    }
};
