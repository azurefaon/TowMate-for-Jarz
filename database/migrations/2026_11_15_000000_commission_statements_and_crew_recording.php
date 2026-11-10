<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6: actual job crew recording, weekly commission statements per towing unit (Draft → For Review → Approved),
 * and settlement records that stop the same booking × unit earnings from being approved twice.
 *
 * - finance_job_personnel: Owner confirmation, correction reasons, substitutes, extra positions, one current slot per
 *   booking × unit (a group job can have one Team Leader per unit).
 * - finance_commission_policies: confirmed_rules, the client's explicit answers for rider/substitute/shared/combined roles.
 * - finance_commission_statements: one statement per week AND unit, a review step, the frozen calculation and its hash.
 * - finance_commission_lines: tied to the job anchor booking and unit; a stored revenue allocation is optional because
 *   automatic allocations are calculated, not stored.
 * - finance_commission_settlements: one ACTIVE settlement per booking × unit across all approved statements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_job_personnel', function (Blueprint $table) {
            $table->boolean('is_substitute')->default(false);
            $table->text('notes')->nullable();
            $table->text('change_reason')->nullable();                     // why this row replaced an earlier one
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('superseded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('supersede_reason')->nullable();                  // why this row was corrected or removed
        });

        Schema::table('finance_commission_policies', function (Blueprint $table) {
            $table->jsonb('confirmed_rules')->nullable();                  // client answers: rider_role, substitutes, shared_role, combined_roles
        });

        Schema::table('finance_commission_statements', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('period_end')->constrained('units')->restrictOnDelete();
            $table->decimal('total_collection', 14, 2)->default(0);
            $table->decimal('total_expenses', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('unallocated_amount', 14, 2)->default(0);
            $table->unsignedInteger('job_count')->default(0);
            $table->jsonb('included_jobs')->nullable();                    // the job keys the Owner selected
            $table->jsonb('calculation')->nullable();                      // CommissionCalculator output for those jobs, money as strings
            $table->char('calculation_hash', 64)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->text('correction_reason')->nullable();                 // set on a revision that corrects a voided statement
        });

        Schema::table('finance_commission_lines', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('booking_id')->constrained('units')->restrictOnDelete();
        });

        Schema::create('finance_commission_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statement_id')->constrained('finance_commission_statements')->restrictOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();   // every member booking of the job
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->string('job_key', 60);
            $table->timestamp('settled_at');
            $table->timestamp('released_at')->nullable();                  // set only when the statement is voided
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('release_reason')->nullable();
            $table->timestamps();

            $table->index('statement_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->postgres();
        }
    }

    private function postgres(): void
    {
        $statements = [
            // ---- job personnel
            'ALTER TABLE finance_job_personnel DROP CONSTRAINT fjp_slot',
            "ALTER TABLE finance_job_personnel ADD CONSTRAINT fjp_slot CHECK (slot IN ('team_leader', 'driver_1', 'driver_2', 'crew_member_1', 'crew_member_2', 'extra_1', 'extra_2', 'extra_3'))",
            'ALTER TABLE finance_job_personnel ADD CONSTRAINT fjp_confirmed CHECK (confirmed_at IS NULL OR needs_review = false)',
            'ALTER TABLE finance_job_personnel ADD CONSTRAINT fjp_supersede_reason CHECK (superseded_at IS NULL OR supersede_reason IS NOT NULL)',
            'DROP INDEX fjp_one_current_per_slot',
            'CREATE UNIQUE INDEX fjp_one_current_per_slot ON finance_job_personnel (booking_id, COALESCE(unit_id, 0), slot) WHERE superseded_at IS NULL',
            'CREATE UNIQUE INDEX fjp_one_role_per_personnel ON finance_job_personnel (booking_id, COALESCE(unit_id, 0), personnel_id, commission_role) WHERE superseded_at IS NULL AND personnel_id IS NOT NULL AND commission_role IS NOT NULL',
            'CREATE UNIQUE INDEX fjp_one_role_per_user ON finance_job_personnel (booking_id, COALESCE(unit_id, 0), user_id, commission_role) WHERE superseded_at IS NULL AND user_id IS NOT NULL AND commission_role IS NOT NULL',
            'DROP TRIGGER fjp_core ON finance_job_personnel',
            'DROP TRIGGER fjp_superseded ON finance_job_personnel',
            'DROP TRIGGER fjp_actors ON finance_job_personnel',
            "CREATE TRIGGER fjp_core BEFORE UPDATE ON finance_job_personnel FOR EACH ROW EXECUTE FUNCTION finance_guard_columns('booking_id','unit_id','slot','personnel_id','name_snapshot','commission_role','borrowed_from_unit_id','source','captured_at','created_at','is_substitute','notes','change_reason')",
            "CREATE TRIGGER fjp_superseded BEFORE UPDATE ON finance_job_personnel FOR EACH ROW WHEN (OLD.superseded_at IS NOT NULL) EXECUTE FUNCTION finance_guard_columns('superseded_at','needs_review','supersede_reason','confirmed_at')",
            "CREATE TRIGGER fjp_confirmed BEFORE UPDATE ON finance_job_personnel FOR EACH ROW WHEN (OLD.confirmed_at IS NOT NULL) EXECUTE FUNCTION finance_guard_columns('confirmed_at','needs_review')",
            "CREATE TRIGGER fjp_actors BEFORE UPDATE ON finance_job_personnel FOR EACH ROW EXECUTE FUNCTION finance_guard_actor_columns('user_id','captured_by','superseded_by_id','confirmed_by','superseded_by_user_id')",

            // ---- policies: the confirmed rules freeze with the rates
            'DROP TRIGGER fcp_frozen ON finance_commission_policies',
            "CREATE TRIGGER fcp_frozen BEFORE UPDATE ON finance_commission_policies FOR EACH ROW WHEN (OLD.status <> 'draft') EXECUTE FUNCTION finance_guard_columns('version','name','effective_from','company_rate','driver_rate','team_leader_rate','rider_rate','crew_mate_rate','interpretation_status','base_definition','allocation_rule','confirmed_rules','notes','activated_at','created_at')",

            // ---- statements
            'ALTER TABLE finance_commission_statements DROP CONSTRAINT fcs_status',
            'ALTER TABLE finance_commission_statements DROP CONSTRAINT fcs_totals',
            'ALTER TABLE finance_commission_statements DROP CONSTRAINT fcs_approved',
            "ALTER TABLE finance_commission_statements ADD CONSTRAINT fcs_status CHECK (status IN ('draft', 'for_review', 'approved', 'voided'))",
            'ALTER TABLE finance_commission_statements ADD CONSTRAINT fcs_totals CHECK (company_amount + personnel_amount + unallocated_amount = total_base AND total_base >= 0 AND unallocated_amount >= 0 AND total_collection >= 0 AND total_expenses >= 0 AND total_deductions >= 0)',
            "ALTER TABLE finance_commission_statements ADD CONSTRAINT fcs_approved CHECK (status IN ('draft', 'for_review') OR (approved_at IS NOT NULL AND approval_hash IS NOT NULL AND approval_snapshot IS NOT NULL AND approved_by_name IS NOT NULL))",
            "ALTER TABLE finance_commission_statements ADD CONSTRAINT fcs_submitted CHECK (status = 'draft' OR submitted_at IS NOT NULL OR approved_at IS NOT NULL)",
            "ALTER TABLE finance_commission_statements ADD CONSTRAINT fcs_approved_complete CHECK (status NOT IN ('approved', 'voided') OR unallocated_amount = 0)",
            "ALTER TABLE finance_commission_statements ADD CONSTRAINT fcs_returned CHECK (returned_at IS NULL OR return_reason IS NOT NULL)",
            'DROP INDEX fcs_one_draft_per_week',
            'DROP INDEX fcs_one_approved_per_week',
            'ALTER TABLE finance_commission_statements DROP CONSTRAINT finance_commission_statements_period_start_revision_unique',
            'CREATE UNIQUE INDEX fcs_revision_per_week_unit ON finance_commission_statements (period_start, COALESCE(unit_id, 0), revision)',
            "CREATE UNIQUE INDEX fcs_one_open_per_week_unit ON finance_commission_statements (period_start, COALESCE(unit_id, 0)) WHERE status IN ('draft', 'for_review')",
            'DROP TRIGGER fcs_frozen ON finance_commission_statements',
            'DROP TRIGGER fcs_status ON finance_commission_statements',
            'DROP TRIGGER fcs_actors ON finance_commission_statements',
            "CREATE TRIGGER fcs_frozen_review BEFORE UPDATE ON finance_commission_statements FOR EACH ROW WHEN (OLD.status = 'for_review') EXECUTE FUNCTION finance_guard_columns('reference_code','period_start','period_end','unit_id','policy_id','revision','supersedes_statement_id','total_base','total_collection','total_expenses','total_deductions','company_amount','personnel_amount','unallocated_amount','line_count','job_count','included_jobs','calculation','calculation_hash','prepared_at','correction_reason','created_at')",
            "CREATE TRIGGER fcs_frozen BEFORE UPDATE ON finance_commission_statements FOR EACH ROW WHEN (OLD.status IN ('approved', 'voided')) EXECUTE FUNCTION finance_guard_columns('reference_code','period_start','period_end','unit_id','policy_id','revision','supersedes_statement_id','total_base','total_collection','total_expenses','total_deductions','company_amount','personnel_amount','unallocated_amount','line_count','job_count','included_jobs','calculation','calculation_hash','prepared_at','correction_reason','submitted_at','approved_at','approved_by_name','approval_hash','approval_snapshot','created_at')",
            "CREATE TRIGGER fcs_status BEFORE UPDATE ON finance_commission_statements FOR EACH ROW EXECUTE FUNCTION finance_guard_status('draft>for_review','for_review>draft','for_review>approved','approved>voided')",
            "CREATE TRIGGER fcs_actors BEFORE UPDATE ON finance_commission_statements FOR EACH ROW EXECUTE FUNCTION finance_guard_actor_columns('prepared_by','submitted_by','approved_by','voided_by')",

            // ---- lines
            'ALTER TABLE finance_commission_lines ALTER COLUMN revenue_allocation_id DROP NOT NULL',
            'DROP INDEX fcl_unique_line',
            'CREATE UNIQUE INDEX fcl_unique_line ON finance_commission_lines (statement_id, booking_id, COALESCE(unit_id, 0), commission_role, recipient_key)',

            // ---- settlements
            'ALTER TABLE finance_commission_settlements ADD CONSTRAINT fcst_release CHECK ((released_at IS NULL) = (release_reason IS NULL))',
            'CREATE UNIQUE INDEX fcst_one_active_per_booking_unit ON finance_commission_settlements (booking_id, unit_id) WHERE released_at IS NULL',
        ];
        foreach ($statements as $sql) {
            DB::statement($sql);
        }

        DB::unprepared(<<<'SQL'
-- A settlement is written only for an approved statement, and released only after that statement is voided.
CREATE OR REPLACE FUNCTION finance_settlement_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    st text;
BEGIN
    SELECT status INTO st FROM finance_commission_statements WHERE id = NEW.statement_id;
    IF TG_OP = 'INSERT' AND st IS DISTINCT FROM 'approved' THEN
        RAISE EXCEPTION 'Earnings can only be settled by an approved commission statement.' USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.released_at IS NULL AND NEW.released_at IS NOT NULL AND st IS DISTINCT FROM 'voided' THEN
        RAISE EXCEPTION 'A settlement is released only when its statement is voided.' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$;
SQL);

        foreach ([
            'CREATE TRIGGER fcst_no_delete BEFORE DELETE ON finance_commission_settlements FOR EACH ROW EXECUTE FUNCTION finance_block_delete()',
            'CREATE TRIGGER fcst_guard BEFORE INSERT OR UPDATE ON finance_commission_settlements FOR EACH ROW EXECUTE FUNCTION finance_settlement_guard()',
            "CREATE TRIGGER fcst_core BEFORE UPDATE ON finance_commission_settlements FOR EACH ROW EXECUTE FUNCTION finance_guard_columns('statement_id','booking_id','unit_id','job_key','settled_at','created_at')",
            "CREATE TRIGGER fcst_released BEFORE UPDATE ON finance_commission_settlements FOR EACH ROW WHEN (OLD.released_at IS NOT NULL) EXECUTE FUNCTION finance_guard_columns('released_at','release_reason')",
            "CREATE TRIGGER fcst_actors BEFORE UPDATE ON finance_commission_settlements FOR EACH ROW EXECUTE FUNCTION finance_guard_actor_columns('released_by')",
        ] as $sql) {
            DB::statement($sql);
        }
    }

    public function down(): void
    {
        // Development rollback only. Never run against a database that holds real finance records.
        Schema::dropIfExists('finance_commission_settlements');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS finance_settlement_guard()');
            foreach ([
                'DROP INDEX IF EXISTS fcl_unique_line',
                'CREATE UNIQUE INDEX fcl_unique_line ON finance_commission_lines (statement_id, revenue_allocation_id, commission_role, recipient_key)',
                'DROP INDEX IF EXISTS fcs_one_open_per_week_unit',
                'DROP INDEX IF EXISTS fcs_revision_per_week_unit',
                'DROP INDEX IF EXISTS fjp_one_role_per_personnel',
                'DROP INDEX IF EXISTS fjp_one_role_per_user',
                'DROP INDEX IF EXISTS fjp_one_current_per_slot',
                'CREATE UNIQUE INDEX fjp_one_current_per_slot ON finance_job_personnel (booking_id, slot) WHERE superseded_at IS NULL',
            ] as $sql) {
                DB::statement($sql);
            }
        }
        Schema::table('finance_commission_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('unit_id'));
        Schema::table('finance_commission_statements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('unit_id');
            $t->dropConstrainedForeignId('submitted_by');
            $t->dropConstrainedForeignId('returned_by');
            $t->dropColumn(['total_collection', 'total_expenses', 'total_deductions', 'unallocated_amount', 'job_count', 'included_jobs',
                'calculation', 'calculation_hash', 'submitted_at', 'returned_at', 'return_reason', 'correction_reason']);
        });
        Schema::table('finance_commission_policies', fn (Blueprint $t) => $t->dropColumn('confirmed_rules'));
        Schema::table('finance_job_personnel', function (Blueprint $t) {
            $t->dropConstrainedForeignId('confirmed_by');
            $t->dropConstrainedForeignId('superseded_by_user_id');
            $t->dropColumn(['is_substitute', 'notes', 'change_reason', 'confirmed_at', 'supersede_reason']);
        });
    }
};
