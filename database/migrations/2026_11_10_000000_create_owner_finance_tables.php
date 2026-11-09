<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner Finance and Commission module, Phase 1 (database foundation).
 *
 * Customer payments are NOT stored again here. Collections stay in bookings, invoices and
 * payment_transactions, and finance_revenue_allocations only points at them.
 *
 * Every money column is NUMERIC(12,2) (exact). Records are permanent: corrections are made by
 * voiding or superseding, never by editing or deleting. PostgreSQL triggers enforce this so that no
 * code path (tinker, a future controller, a bug) can rewrite approved history.
 *
 * See docs/owner-finance/spec.md.
 */
return new class extends Migration
{
    private const TABLES = [
        'finance_payouts',
        'finance_commission_lines',
        'finance_commission_statements',
        'finance_commission_policies',
        'finance_revenue_allocations',
        'finance_job_personnel',
        'finance_expense_evidence',
        'finance_expenses',
        'finance_fund_entries',
    ];

    public function up(): void
    {
        // 1. Unit funds: cash the Owner issues to a unit, and cash the unit returns. Kept separate from expenses and collections.
        Schema::create('finance_fund_entries', function (Blueprint $table) {
            $table->id();
            $table->string('reference_code', 40)->unique();
            $table->uuid('idempotency_key')->unique();                       // one submit = one row, even on double-click or retry
            $table->string('entry_type', 12);                                // issuance | return
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('team_leader_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('counterparty_name', 150)->nullable();            // who received / handed back the cash (snapshot)
            $table->decimal('amount', 12, 2);
            $table->date('entry_date');
            $table->foreignId('issuance_id')->nullable()->constrained('finance_fund_entries')->restrictOnDelete(); // a return may point at the issuance it settles
            $table->text('notes')->nullable();
            $table->string('status', 12)->default('recorded');               // recorded | voided
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['unit_id', 'entry_date']);
        });

        // 2. Expenses: Team Leader submits -> Dispatcher verifies -> Owner approves.
        Schema::create('finance_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('reference_code', 40)->unique();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->restrictOnDelete();
            $table->foreignId('fund_entry_id')->nullable()->constrained('finance_fund_entries')->restrictOnDelete();
            $table->string('category', 40);                                 // validated against config('finance.expense_categories')
            $table->text('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('expense_date');
            $table->string('funding_source', 30);                           // unit_fund | company_direct | crew_out_of_pocket
            $table->string('status', 12)->default('submitted');             // submitted | verified | approved | rejected | voided
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['unit_id', 'expense_date']);
            $table->index(['status', 'expense_date']);
        });

        // 3. Protected evidence (receipt photos), kept on the private disk and served only through an authorised route.
        Schema::create('finance_expense_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('finance_expenses')->restrictOnDelete();
            $table->string('disk', 20)->default('local');
            $table->string('path')->unique();
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['expense_id', 'sha256']);                        // the same file cannot be attached twice
        });

        // 4. Who actually worked each job. Units only hold their CURRENT crew, so this is the historical record.
        Schema::create('finance_job_personnel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->string('slot', 20);                                     // team_leader | driver_1 | driver_2 | crew_member_1 | crew_member_2
            $table->foreignId('personnel_id')->nullable()->constrained('personnel')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name_snapshot', 150);
            $table->string('commission_role', 20)->nullable();              // driver | team_leader | rider | crew_mate (mapping pending client confirmation)
            $table->foreignId('borrowed_from_unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->string('source', 20);                                   // completion_capture | backfill | manual
            $table->boolean('needs_review')->default(false);                // backfilled guesses stay flagged until the Owner confirms them
            $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('captured_at');
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('finance_job_personnel')->restrictOnDelete();
            $table->timestamps();

            $table->index('booking_id');
        });

        // 5. Collected revenue attributed to a unit for a booking. It points at the existing payment records and never copies them.
        Schema::create('finance_revenue_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('gross_amount', 12, 2);                          // VAT-inclusive collected share
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2);                            // gross - VAT
            $table->string('allocation_method', 30);                        // single_booking | group_share
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->timestamp('collected_at');                               // bookings.completed_at (payment verified)
            $table->foreignId('computed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('finance_revenue_allocations')->restrictOnDelete();
            $table->timestamps();

            $table->index(['unit_id', 'collected_at']);
        });

        // 6. Versioned commission policies. Rates are frozen once a version leaves draft.
        Schema::create('finance_commission_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->string('name', 120);
            $table->string('status', 12)->default('draft');                 // draft | active | retired
            $table->date('effective_from')->nullable();
            $table->decimal('company_rate', 5, 2);
            $table->decimal('driver_rate', 5, 2);
            $table->decimal('team_leader_rate', 5, 2);
            $table->decimal('rider_rate', 5, 2);
            $table->decimal('crew_mate_rate', 5, 2);
            $table->string('interpretation_status', 30)->default('pending_client_confirmation'); // pending_client_confirmation | confirmed
            $table->string('base_definition', 60)->nullable();              // which amount the rates apply to (client decision)
            $table->string('allocation_rule', 60)->nullable();              // pool vs per-person, empty-role handling (client decision)
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        // 7. Weekly commission statements (Monday–Saturday). Approval details are frozen with a content hash.
        Schema::create('finance_commission_statements', function (Blueprint $table) {
            $table->id();
            $table->string('reference_code', 40)->unique();
            $table->date('period_start');                                   // a Monday
            $table->date('period_end');                                     // the Saturday of the same week
            $table->foreignId('policy_id')->constrained('finance_commission_policies')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('supersedes_statement_id')->nullable()->constrained('finance_commission_statements')->restrictOnDelete();
            $table->string('status', 12)->default('draft');                 // draft | approved | voided
            $table->decimal('total_base', 14, 2)->default(0);
            $table->decimal('company_amount', 14, 2)->default(0);
            $table->decimal('personnel_amount', 14, 2)->default(0);
            $table->unsignedInteger('line_count')->default(0);
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_name', 150)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->char('approval_hash', 64)->nullable();                   // sha256 of the canonical approval snapshot
            $table->jsonb('approval_snapshot')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['period_start', 'revision']);
        });

        Schema::create('finance_commission_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statement_id')->constrained('finance_commission_statements')->restrictOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignId('revenue_allocation_id')->constrained('finance_revenue_allocations')->restrictOnDelete();
            $table->foreignId('job_personnel_id')->nullable()->constrained('finance_job_personnel')->restrictOnDelete();
            $table->string('commission_role', 20);                          // company | driver | team_leader | rider | crew_mate
            $table->foreignId('recipient_personnel_id')->nullable()->constrained('personnel')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_key', 60);                            // stable payee key: company | personnel:{id} | user:{id}
            $table->string('recipient_name', 150);
            $table->decimal('base_amount', 12, 2);
            $table->decimal('rate', 7, 4);
            $table->decimal('amount', 12, 2);
            $table->decimal('rounding_adjustment', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['statement_id', 'recipient_key']);
        });

        // 8. Crew payouts, kept apart from commission lines: what was EARNED never merges with what was PAID.
        Schema::create('finance_payouts', function (Blueprint $table) {
            $table->id();
            $table->string('reference_code', 40)->unique();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('statement_id')->constrained('finance_commission_statements')->restrictOnDelete();
            $table->string('recipient_key', 60);
            $table->foreignId('recipient_personnel_id')->nullable()->constrained('personnel')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_name', 150);
            $table->decimal('amount', 12, 2);
            $table->string('payout_method', 20);                            // cash | gcash | bank_transfer
            $table->string('external_reference', 100)->nullable();
            $table->timestamp('paid_at');
            $table->string('status', 12)->default('recorded');              // recorded | voided
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('audit_log_id')->nullable()->constrained('audit_logs')->nullOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->addConstraints();
            $this->addPartialUniqueIndexes();
            $this->addImmutabilityTriggers();
        }

        // The client's initial rates, as an unconfirmed DRAFT. It cannot be activated or used for an approved
        // statement until the commission interpretation is confirmed. This is configuration, not a financial record.
        DB::table('finance_commission_policies')->insert([
            'version' => 1,
            'name' => 'Initial commission rates',
            'status' => 'draft',
            'company_rate' => '60.00',
            'driver_rate' => '12.00',
            'team_leader_rate' => '10.00',
            'rider_rate' => '10.00',
            'crew_mate_rate' => '8.00',
            'interpretation_status' => 'pending_client_confirmation',
            'notes' => 'Initial client rates. Base amount, pool vs per-person split and empty-role handling await client confirmation.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addConstraints(): void
    {
        $checks = [
            'finance_fund_entries' => [
                'ffe_type' => "entry_type IN ('issuance', 'return')",
                'ffe_amount' => 'amount > 0',
                'ffe_status' => "status IN ('recorded', 'voided')",
                'ffe_issuance_link' => "entry_type = 'return' OR issuance_id IS NULL",
                'ffe_void_complete' => "(status = 'voided') = (voided_at IS NOT NULL AND void_reason IS NOT NULL)",
            ],
            'finance_expenses' => [
                'fex_amount' => 'amount > 0',
                'fex_status' => "status IN ('submitted', 'verified', 'approved', 'rejected', 'voided')",
                'fex_funding' => "funding_source IN ('unit_fund', 'company_direct', 'crew_out_of_pocket')",
                'fex_verified' => "status NOT IN ('verified') OR verified_at IS NOT NULL",
                'fex_approved' => "status NOT IN ('approved', 'voided') OR approved_at IS NOT NULL",
                'fex_rejected' => "status <> 'rejected' OR (rejected_at IS NOT NULL AND rejection_reason IS NOT NULL)",
                'fex_voided' => "status <> 'voided' OR (voided_at IS NOT NULL AND void_reason IS NOT NULL)",
            ],
            'finance_expense_evidence' => [
                'fee_size' => 'size_bytes > 0',
                'fee_sha' => "sha256 ~ '^[0-9a-f]{64}$'",
            ],
            'finance_job_personnel' => [
                'fjp_slot' => "slot IN ('team_leader', 'driver_1', 'driver_2', 'crew_member_1', 'crew_member_2')",
                'fjp_role' => "commission_role IS NULL OR commission_role IN ('driver', 'team_leader', 'rider', 'crew_mate')",
                'fjp_source' => "source IN ('completion_capture', 'backfill', 'manual')",
                'fjp_supersede' => 'superseded_by_id IS NULL OR superseded_at IS NOT NULL',
            ],
            'finance_revenue_allocations' => [
                'fra_amounts' => 'gross_amount >= 0 AND vat_amount >= 0 AND net_amount = gross_amount - vat_amount',
                'fra_method' => "allocation_method IN ('single_booking', 'group_share')",
                'fra_supersede' => 'superseded_by_id IS NULL OR superseded_at IS NOT NULL',
            ],
            'finance_commission_policies' => [
                'fcp_status' => "status IN ('draft', 'active', 'retired')",
                'fcp_rates_range' => 'company_rate >= 0 AND driver_rate >= 0 AND team_leader_rate >= 0 AND rider_rate >= 0 AND crew_mate_rate >= 0',
                'fcp_rates_total' => 'company_rate + driver_rate + team_leader_rate + rider_rate + crew_mate_rate = 100',
                'fcp_interpretation' => "interpretation_status IN ('pending_client_confirmation', 'confirmed')",
                'fcp_active_confirmed' => "status = 'draft' OR (interpretation_status = 'confirmed' AND activated_at IS NOT NULL AND effective_from IS NOT NULL)",
            ],
            'finance_commission_statements' => [
                'fcs_week' => 'EXTRACT(ISODOW FROM period_start) = 1 AND period_end = period_start + 5',
                'fcs_status' => "status IN ('draft', 'approved', 'voided')",
                'fcs_totals' => 'company_amount + personnel_amount = total_base AND total_base >= 0',
                'fcs_approved' => "status = 'draft' OR (approved_at IS NOT NULL AND approval_hash IS NOT NULL AND approval_snapshot IS NOT NULL AND approved_by_name IS NOT NULL)",
                'fcs_voided' => "status <> 'voided' OR (voided_at IS NOT NULL AND void_reason IS NOT NULL)",
            ],
            'finance_commission_lines' => [
                'fcl_role' => "commission_role IN ('company', 'driver', 'team_leader', 'rider', 'crew_mate')",
                'fcl_amounts' => 'base_amount >= 0 AND amount >= 0 AND rate >= 0 AND rate <= 100',
            ],
            'finance_payouts' => [
                'fpo_amount' => 'amount > 0',
                'fpo_method' => "payout_method IN ('cash', 'gcash', 'bank_transfer')",
                'fpo_status' => "status IN ('recorded', 'voided')",
                'fpo_not_company' => "recipient_key <> 'company'",
                'fpo_voided' => "(status = 'voided') = (voided_at IS NOT NULL AND void_reason IS NOT NULL)",
            ],
        ];

        foreach ($checks as $table => $constraints) {
            foreach ($constraints as $name => $expression) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }
    }

    private function addPartialUniqueIndexes(): void
    {
        DB::statement("CREATE UNIQUE INDEX fcp_one_active ON finance_commission_policies (status) WHERE status = 'active'");
        DB::statement("CREATE UNIQUE INDEX fcs_one_draft_per_week ON finance_commission_statements (period_start) WHERE status = 'draft'");
        DB::statement("CREATE UNIQUE INDEX fcs_one_approved_per_week ON finance_commission_statements (period_start) WHERE status = 'approved'");
        DB::statement('CREATE UNIQUE INDEX fjp_one_current_per_slot ON finance_job_personnel (booking_id, slot) WHERE superseded_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX fra_one_current_per_booking_unit ON finance_revenue_allocations (booking_id, unit_id) WHERE superseded_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX fcl_unique_line ON finance_commission_lines (statement_id, revenue_allocation_id, commission_role, recipient_key)');
        DB::statement("CREATE UNIQUE INDEX fpo_one_recorded_per_recipient ON finance_payouts (statement_id, recipient_key) WHERE status = 'recorded'");
    }

    private function addImmutabilityTriggers(): void
    {
        // Generic guards. Each takes its column list / allowed transitions as trigger arguments.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION finance_block_delete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Finance records are permanent: rows in % cannot be deleted. Void or supersede them instead.', TG_TABLE_NAME
        USING ERRCODE = 'P0001';
END $$;

CREATE OR REPLACE FUNCTION finance_block_delete_unless_draft() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status <> 'draft' THEN
        RAISE EXCEPTION 'Only draft rows in % can be deleted.', TG_TABLE_NAME USING ERRCODE = 'P0001';
    END IF;
    RETURN OLD;
END $$;

-- Raises if any listed column changes.
CREATE OR REPLACE FUNCTION finance_guard_columns() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    col text;
    o jsonb := to_jsonb(OLD);
    n jsonb := to_jsonb(NEW);
BEGIN
    FOREACH col IN ARRAY TG_ARGV LOOP
        IF o -> col IS DISTINCT FROM n -> col THEN
            RAISE EXCEPTION 'Finance record %.% is locked and cannot be changed (row %).', TG_TABLE_NAME, col, OLD.id
                USING ERRCODE = 'P0001';
        END IF;
    END LOOP;
    RETURN NEW;
END $$;

-- Actor columns: may be filled once, or cleared when the user is deleted, but never reassigned to someone else.
CREATE OR REPLACE FUNCTION finance_guard_actor_columns() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    col text;
    o jsonb := to_jsonb(OLD);
    n jsonb := to_jsonb(NEW);
BEGIN
    FOREACH col IN ARRAY TG_ARGV LOOP
        IF jsonb_typeof(o -> col) <> 'null' AND jsonb_typeof(n -> col) <> 'null' AND o -> col IS DISTINCT FROM n -> col THEN
            RAISE EXCEPTION 'Finance record %.% cannot be reassigned (row %).', TG_TABLE_NAME, col, OLD.id
                USING ERRCODE = 'P0001';
        END IF;
    END LOOP;
    RETURN NEW;
END $$;

-- Arguments are the allowed 'from>to' status transitions.
CREATE OR REPLACE FUNCTION finance_guard_status() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status IS DISTINCT FROM NEW.status AND NOT ((OLD.status || '>' || NEW.status) = ANY (TG_ARGV)) THEN
        RAISE EXCEPTION 'Status change % -> % is not allowed on %.', OLD.status, NEW.status, TG_TABLE_NAME
            USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$;

-- Commission lines can only be written while their statement is a draft.
CREATE OR REPLACE FUNCTION finance_lines_require_draft() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    st text;
BEGIN
    SELECT status INTO st FROM finance_commission_statements
        WHERE id = (CASE WHEN TG_OP = 'DELETE' THEN OLD.statement_id ELSE NEW.statement_id END);
    IF st IS DISTINCT FROM 'draft' THEN
        RAISE EXCEPTION 'Commission lines are locked once their statement is %.', st USING ERRCODE = 'P0001';
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.statement_id IS DISTINCT FROM NEW.statement_id THEN
        RAISE EXCEPTION 'A commission line cannot move to another statement.' USING ERRCODE = 'P0001';
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;

-- Payouts can only be recorded against an approved statement.
CREATE OR REPLACE FUNCTION finance_payout_requires_approved() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF (SELECT status FROM finance_commission_statements WHERE id = NEW.statement_id) IS DISTINCT FROM 'approved' THEN
        RAISE EXCEPTION 'Payouts can only be recorded against an approved commission statement.' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$;

-- A statement with recorded payouts cannot be voided.
CREATE OR REPLACE FUNCTION finance_statement_void_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.status = 'voided' AND OLD.status <> 'voided'
       AND EXISTS (SELECT 1 FROM finance_payouts WHERE statement_id = OLD.id AND status = 'recorded') THEN
        RAISE EXCEPTION 'Void the recorded payouts before voiding this statement.' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$;

-- Evidence can only be added while the expense is still under review.
CREATE OR REPLACE FUNCTION finance_evidence_requires_open_expense() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF (SELECT status FROM finance_expenses WHERE id = NEW.expense_id) NOT IN ('submitted', 'verified') THEN
        RAISE EXCEPTION 'Evidence cannot be added to a finalised expense.' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$;
SQL);

        $triggers = [
            // Unit funds
            "CREATE TRIGGER ffe_no_delete BEFORE DELETE ON finance_fund_entries FOR EACH ROW EXECUTE FUNCTION finance_block_delete()",
            "CREATE TRIGGER ffe_core BEFORE UPDATE ON finance_fund_entries FOR EACH ROW EXECUTE FUNCTION finance_guard_columns('reference_code','idempotency_key','entry_type','unit_id','counterparty_name','amount','entry_date','issuance_id','notes','created_at')",
            "CREATE TRIGGER ffe_status BEFORE UPDATE ON finance_fund_entries FOR EACH ROW EXECUTE FUNCTION finance_guard_status('recorded>voided')",
            "CREATE TRIGGER ffe_voided BEFORE UPDATE ON finance_fund_entries FOR EACH ROW WHEN (OLD.status = 'voided') EXECUTE FUNCTION finance_guard_columns('voided_at','void_reason')",
            "CREATE TRIGGER ffe_actors BEFORE UPDATE ON finance_fund_entries FOR EACH ROW EXECUTE FUNCTION finance_guard_actor_columns('recorded_by','team_leader_user_id','voided_by')",

            // Expenses
            "CREATE TRIGGER fex_no_delete BEFORE DELETE ON finance_expenses FOR EACH ROW EXECUTE FUNCTION finance_block_delete()",
            "CREATE TRIGGER fex_core BEFORE UPDATE ON finance_expenses FOR EACH ROW EXECUTE FUNCTION finance_guard_columns('reference_code','idempotency_key','submitted_at','created_at')",
            "CREATE TRIGGER fex_details BEFORE UPDATE ON finance_expenses FOR EACH ROW WHEN (OLD.status <> 'submitted') EXECUTE FUNCTION finance_guard_columns('unit_id','booking_id','fund_entry_id','category','description','amount','expense_date','funding_source')",
            "CREATE TRIGGER fex_final BEFORE UPDATE ON finance_expenses FOR EACH ROW WHEN (OLD.status IN ('approved','rejected','voided')) EXECUTE FUNCTION finance_guard_columns('verified_at','verification_note','approved_at','rejected_at','rejection_reason')",
            "CREATE TRIGGER fex_voided BEFORE UPDATE ON finance_expenses FOR EACH ROW WHEN (OLD.status = 'voided') EXECUTE FUNCTION finance_guard_columns('voided_at','void_reason')",
            "CREATE TRIGGER fex_status BEFORE UPDATE ON finance_expenses FOR EACH ROW EXECUTE FUNCTION finance_guard_status('submitted>verified','submitted>approved','submitted>rejected','verified>approved','verified>rejected','approved>voided')",
            "CREATE TRIGGER fex_actors BEFORE UPDATE ON finance_expenses FOR EACH ROW EXECUTE FUNCTION finance_guard_actor_columns('submitted_by','verified_by','approved_by','rejected_by','voided_by')",

            // Evidence
            "CREATE TRIGGER fee_no_delete BEFORE DELETE ON finance_expense_evidence FOR EACH ROW EXECUTE FUNCTION finance_block_delete()",
            "CREATE TRIGGER fee_core BEFORE UPDATE ON finance_expense_evidence FOR EACH ROW EXECUTE FUNCTION finance_guard_columns('expense_id','disk','path','original_name','mime_type','size_bytes','sha256','created_at')",
            "CREATE TRIGGER fee_actors BEFORE UPDATE ON finance_expense_evidence FOR EACH ROW EXECUTE FUNCTION finance_guard_actor_columns('uploaded_by')",
            "CREATE TRIGGER fee_open_expense BEFORE INSERT ON finance_expense_evidence FOR EACH ROW EXECUTE FUNCTION finance_evidence_requires_open_expense()",

            // Job personnel history
            "CREATE TRIGGER fjp_no_delete BEFORE DELETE ON finance_job_personnel FOR EACH ROW EXECUTE FUNCTION finance_block_delete()",
            "CREATE TRIGGER fjp_core BEFORE UPDATE ON finance_job_personnel FOR EACH ROW EXECUTE FUNCTION finance_guard_columns('booking_id','unit_id','slot','personnel_id','name_snapshot','commission_role','borrowed_from_unit_id','source','captured_at','created_at')",
            "CREATE TRIGGER fjp_superseded BEFORE UPDATE ON finance_job_personnel FOR EACH ROW WHEN (OLD.superseded_at IS NOT NULL) EXECUTE FUNCTION finance_guard_columns('superseded_at','needs_review')",
            "CREATE TRIGGER fjp_actors BEFORE UPDATE ON finance_job_personnel FOR EACH ROW EXECUTE FUNCTION finance_guard_actor_columns('user_id','captured_by','superseded_by_id')",

            // Revenue allocations
            "CREATE TRIGGER fra_no_delete BEFORE DELETE ON finance_revenue_allocations FOR EACH ROW EXECUTE FUNCTION finance_block_delete()",
            "CREATE TRIGGER fra_core BEFORE UPDATE ON finance_revenue_allocations FOR EACH ROW EXECUTE FUNCTION finance_guard_columns('booking_id','unit_id','gross_amount','vat_amount','net_amount','allocation_method','collected_at','created_at')",
            "CREATE TRIGGER fra_superseded BEFORE UPDATE ON finance_revenue_allocations FOR EACH ROW WHEN (OLD.superseded_at IS NOT NULL) EXECUTE FUNCTION finance_guard_columns('superseded_at')",
            "CREATE TRIGGER fra_actors BEFORE UPDATE ON finance_revenue_allocations FOR EACH ROW EXECUTE FUNCTION finance_guard_actor_columns('computed_by','invoice_id','payment_transaction_id','superseded_by_id')",

            // Commission policies
            "CREATE TRIGGER fcp_delete BEFORE DELETE ON finance_commission_policies FOR EACH ROW EXECUTE FUNCTION finance_block_delete_unless_draft()",
            "CREATE TRIGGER fcp_frozen BEFORE UPDATE ON finance_commission_policies FOR EACH ROW WHEN (OLD.status <> 'draft') EXECUTE FUNCTION finance_guard_columns('version','name','effective_from','company_rate','driver_rate','team_leader_rate','rider_rate','crew_mate_rate','interpretation_status','base_definition','allocation_rule','notes','activated_at','created_at')",
            "CREATE TRIGGER fcp_retired BEFORE UPDATE ON finance_commission_policies FOR EACH ROW WHEN (OLD.status = 'retired') EXECUTE FUNCTION finance_guard_columns('retired_at')",
            "CREATE TRIGGER fcp_status BEFORE UPDATE ON finance_commission_policies FOR EACH ROW EXECUTE FUNCTION finance_guard_status('draft>active','active>retired')",
            "CREATE TRIGGER fcp_actors BEFORE UPDATE ON finance_commission_policies FOR EACH ROW EXECUTE FUNCTION finance_guard_actor_columns('created_by','activated_by','retired_by')",

            // Commission statements
            "CREATE TRIGGER fcs_delete BEFORE DELETE ON finance_commission_statements FOR EACH ROW EXECUTE FUNCTION finance_block_delete_unless_draft()",
            "CREATE TRIGGER fcs_frozen BEFORE UPDATE ON finance_commission_statements FOR EACH ROW WHEN (OLD.status <> 'draft') EXECUTE FUNCTION finance_guard_columns('reference_code','period_start','period_end','policy_id','revision','supersedes_statement_id','total_base','company_amount','personnel_amount','line_count','prepared_at','approved_at','approved_by_name','approval_hash','approval_snapshot','created_at')",
            "CREATE TRIGGER fcs_voided BEFORE UPDATE ON finance_commission_statements FOR EACH ROW WHEN (OLD.status = 'voided') EXECUTE FUNCTION finance_guard_columns('voided_at','void_reason')",
            "CREATE TRIGGER fcs_status BEFORE UPDATE ON finance_commission_statements FOR EACH ROW EXECUTE FUNCTION finance_guard_status('draft>approved','approved>voided')",
            "CREATE TRIGGER fcs_void_guard BEFORE UPDATE ON finance_commission_statements FOR EACH ROW EXECUTE FUNCTION finance_statement_void_guard()",
            "CREATE TRIGGER fcs_actors BEFORE UPDATE ON finance_commission_statements FOR EACH ROW EXECUTE FUNCTION finance_guard_actor_columns('prepared_by','approved_by','voided_by')",

            // Commission lines
            "CREATE TRIGGER fcl_draft_only BEFORE INSERT OR UPDATE OR DELETE ON finance_commission_lines FOR EACH ROW EXECUTE FUNCTION finance_lines_require_draft()",

            // Payouts
            "CREATE TRIGGER fpo_no_delete BEFORE DELETE ON finance_payouts FOR EACH ROW EXECUTE FUNCTION finance_block_delete()",
            "CREATE TRIGGER fpo_requires_approved BEFORE INSERT ON finance_payouts FOR EACH ROW EXECUTE FUNCTION finance_payout_requires_approved()",
            "CREATE TRIGGER fpo_core BEFORE UPDATE ON finance_payouts FOR EACH ROW EXECUTE FUNCTION finance_guard_columns('reference_code','idempotency_key','statement_id','recipient_key','recipient_personnel_id','recipient_name','amount','payout_method','external_reference','paid_at','created_at')",
            "CREATE TRIGGER fpo_voided BEFORE UPDATE ON finance_payouts FOR EACH ROW WHEN (OLD.status = 'voided') EXECUTE FUNCTION finance_guard_columns('voided_at','void_reason')",
            "CREATE TRIGGER fpo_status BEFORE UPDATE ON finance_payouts FOR EACH ROW EXECUTE FUNCTION finance_guard_status('recorded>voided')",
            "CREATE TRIGGER fpo_actors BEFORE UPDATE ON finance_payouts FOR EACH ROW EXECUTE FUNCTION finance_guard_actor_columns('recorded_by','recipient_user_id','voided_by','audit_log_id')",
        ];

        foreach ($triggers as $sql) {
            DB::statement($sql);
        }
    }

    public function down(): void
    {
        // Development rollback only. Never run against a database that holds real finance records.
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                'finance_block_delete()', 'finance_block_delete_unless_draft()', 'finance_guard_columns()',
                'finance_guard_actor_columns()', 'finance_guard_status()', 'finance_lines_require_draft()',
                'finance_payout_requires_approved()', 'finance_statement_void_guard()', 'finance_evidence_requires_open_expense()',
            ] as $function) {
                DB::statement("DROP FUNCTION IF EXISTS {$function}");
            }
        }
    }
};
