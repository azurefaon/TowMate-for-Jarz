<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7: payout records and the Paid statement status.
 *
 * - finance_payouts: the recipient's roles, the recording Owner's name, optional supporting evidence (private disk),
 *   and a link to the payout it corrects. Every payout needs a transaction reference or evidence. Unallocated shares
 *   are never payable. Payouts of a Paid statement cannot be voided.
 * - finance_commission_statements: status "paid" (approved → paid only), with who marked it and when. The database
 *   refuses the transition unless recorded payouts total the statement's personnel amount exactly.
 *
 * Recording a payout never moves money: TowMate only records a transfer the Owner already made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_payouts', function (Blueprint $table) {
            $table->string('recipient_roles', 150)->nullable();
            $table->string('recorded_by_name', 150)->nullable();
            $table->string('evidence_path', 255)->nullable();
            $table->string('evidence_original_name', 255)->nullable();
            $table->string('evidence_mime', 100)->nullable();
            $table->char('evidence_sha256', 64)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('corrects_payout_id')->nullable()->constrained('finance_payouts')->restrictOnDelete();
        });

        Schema::table('finance_commission_statements', function (Blueprint $table) {
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('paid_by_name', 150)->nullable();
            $table->timestamp('paid_at')->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            // payouts
            "ALTER TABLE finance_payouts ADD CONSTRAINT fpo_reference CHECK (external_reference IS NOT NULL OR evidence_path IS NOT NULL)",
            "ALTER TABLE finance_payouts ADD CONSTRAINT fpo_not_unallocated CHECK (recipient_key <> 'unallocated')",
            "ALTER TABLE finance_payouts ADD CONSTRAINT fpo_evidence_sha CHECK (evidence_path IS NULL OR evidence_sha256 ~ '^[0-9a-f]{64}$')",
            'DROP TRIGGER fpo_core ON finance_payouts',
            "CREATE TRIGGER fpo_core BEFORE UPDATE ON finance_payouts FOR EACH ROW EXECUTE FUNCTION finance_guard_columns('reference_code','idempotency_key','statement_id','recipient_key','recipient_personnel_id','recipient_name','recipient_roles','amount','payout_method','external_reference','paid_at','recorded_by_name','evidence_path','evidence_original_name','evidence_mime','evidence_sha256','notes','corrects_payout_id','created_at')",

            // statements
            'ALTER TABLE finance_commission_statements DROP CONSTRAINT fcs_status',
            "ALTER TABLE finance_commission_statements ADD CONSTRAINT fcs_status CHECK (status IN ('draft', 'for_review', 'approved', 'paid', 'voided'))",
            'ALTER TABLE finance_commission_statements DROP CONSTRAINT fcs_approved_complete',
            "ALTER TABLE finance_commission_statements ADD CONSTRAINT fcs_approved_complete CHECK (status NOT IN ('approved', 'paid', 'voided') OR unallocated_amount = 0)",
            "ALTER TABLE finance_commission_statements ADD CONSTRAINT fcs_paid CHECK ((status = 'paid') = (paid_at IS NOT NULL AND paid_by_name IS NOT NULL))",
            'DROP TRIGGER fcs_frozen ON finance_commission_statements',
            "CREATE TRIGGER fcs_frozen BEFORE UPDATE ON finance_commission_statements FOR EACH ROW WHEN (OLD.status IN ('approved', 'paid', 'voided')) EXECUTE FUNCTION finance_guard_columns('reference_code','period_start','period_end','unit_id','policy_id','revision','supersedes_statement_id','total_base','total_collection','total_expenses','total_deductions','company_amount','personnel_amount','unallocated_amount','line_count','job_count','included_jobs','calculation','calculation_hash','prepared_at','correction_reason','submitted_at','approved_at','approved_by_name','approval_hash','approval_snapshot','created_at')",
            "CREATE TRIGGER fcs_paid_frozen BEFORE UPDATE ON finance_commission_statements FOR EACH ROW WHEN (OLD.status = 'paid') EXECUTE FUNCTION finance_guard_columns('paid_at','paid_by_name')",
            'DROP TRIGGER fcs_status ON finance_commission_statements',
            "CREATE TRIGGER fcs_status BEFORE UPDATE ON finance_commission_statements FOR EACH ROW EXECUTE FUNCTION finance_guard_status('draft>for_review','for_review>draft','for_review>approved','approved>paid','approved>voided')",
            'DROP TRIGGER fcs_actors ON finance_commission_statements',
            "CREATE TRIGGER fcs_actors BEFORE UPDATE ON finance_commission_statements FOR EACH ROW EXECUTE FUNCTION finance_guard_actor_columns('prepared_by','submitted_by','approved_by','paid_by','voided_by')",
        ] as $sql) {
            DB::statement($sql);
        }

        DB::unprepared(<<<'SQL'
-- Paid only when recorded payouts reconcile exactly to the personnel amount.
CREATE OR REPLACE FUNCTION finance_statement_paid_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.status = 'paid' AND OLD.status <> 'paid'
       AND (SELECT COALESCE(SUM(amount), 0) FROM finance_payouts WHERE statement_id = OLD.id AND status = 'recorded') <> OLD.personnel_amount THEN
        RAISE EXCEPTION 'A statement is Paid only when recorded payouts equal its personnel amount exactly.' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$;

-- The payouts of a Paid statement are final.
CREATE OR REPLACE FUNCTION finance_payout_void_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.status = 'voided' AND OLD.status <> 'voided'
       AND (SELECT status FROM finance_commission_statements WHERE id = OLD.statement_id) = 'paid' THEN
        RAISE EXCEPTION 'Payouts of a Paid statement cannot be voided.' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$;
SQL);

        DB::statement('CREATE TRIGGER fcs_paid_guard BEFORE UPDATE ON finance_commission_statements FOR EACH ROW EXECUTE FUNCTION finance_statement_paid_guard()');
        DB::statement('CREATE TRIGGER fpo_void_guard BEFORE UPDATE ON finance_payouts FOR EACH ROW EXECUTE FUNCTION finance_payout_void_guard()');
    }

    public function down(): void
    {
        // Development rollback only. Never run against a database that holds real finance records.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS fcs_paid_guard ON finance_commission_statements');
            DB::statement('DROP TRIGGER IF EXISTS fcs_paid_frozen ON finance_commission_statements');
            DB::statement('DROP TRIGGER IF EXISTS fpo_void_guard ON finance_payouts');
            DB::statement('DROP FUNCTION IF EXISTS finance_statement_paid_guard()');
            DB::statement('DROP FUNCTION IF EXISTS finance_payout_void_guard()');
            DB::statement('ALTER TABLE finance_payouts DROP CONSTRAINT IF EXISTS fpo_reference');
            DB::statement('ALTER TABLE finance_payouts DROP CONSTRAINT IF EXISTS fpo_not_unallocated');
            DB::statement('ALTER TABLE finance_payouts DROP CONSTRAINT IF EXISTS fpo_evidence_sha');
            DB::statement('ALTER TABLE finance_commission_statements DROP CONSTRAINT IF EXISTS fcs_paid');
        }
        Schema::table('finance_commission_statements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('paid_by');
            $t->dropColumn(['paid_by_name', 'paid_at']);
        });
        Schema::table('finance_payouts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('corrects_payout_id');
            $t->dropColumn(['recipient_roles', 'recorded_by_name', 'evidence_path', 'evidence_original_name', 'evidence_mime', 'evidence_sha256', 'notes']);
        });
    }
};
