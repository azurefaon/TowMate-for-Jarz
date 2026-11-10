<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner Finance Phase 5 (commission calculation): deductions from a job's sharing base that are not expenses,
 * such as payment-provider fees, refunds to the customer, or damage charges.
 *
 * Sharing base = allocated verified collection − approved job expenses − APPROVED deductions.
 * A deduction is recorded as pending review and only reduces the base once the Owner approves it. Pending
 * deductions block commission approval. Duplicates are refused: one active deduction per (job, unit, type, external
 * reference), plus an idempotency key per submission. Like every finance record, deductions are voided, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_commission_deductions', function (Blueprint $table) {
            $table->id();
            $table->string('reference_code', 40)->unique();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->string('deduction_type', 20);                              // payment_fee | refund | damage | other
            $table->decimal('amount', 12, 2);
            $table->text('description');
            $table->string('external_reference', 100)->nullable();            // provider fee id, refund slip no., ...
            $table->string('status', 16)->default('pending_review');          // pending_review | approved | voided
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'unit_id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'fcd_type' => "deduction_type IN ('payment_fee', 'refund', 'damage', 'other')",
            'fcd_amount' => 'amount > 0',
            'fcd_status' => "status IN ('pending_review', 'approved', 'voided')",
            'fcd_approved' => "status <> 'approved' OR approved_at IS NOT NULL",
            'fcd_voided' => "(status = 'voided') = (voided_at IS NOT NULL AND void_reason IS NOT NULL)",
        ] as $name => $check) {
            DB::statement("ALTER TABLE finance_commission_deductions ADD CONSTRAINT {$name} CHECK ({$check})");
        }

        // The same fee or refund cannot be deducted twice from one job and unit.
        DB::statement("CREATE UNIQUE INDEX fcd_one_active_per_reference ON finance_commission_deductions (booking_id, unit_id, deduction_type, external_reference) WHERE external_reference IS NOT NULL AND status <> 'voided'");

        DB::statement('CREATE TRIGGER fcd_no_delete BEFORE DELETE ON finance_commission_deductions FOR EACH ROW EXECUTE FUNCTION finance_block_delete()');
        DB::statement("CREATE TRIGGER fcd_core BEFORE UPDATE ON finance_commission_deductions FOR EACH ROW EXECUTE FUNCTION finance_guard_columns('reference_code','idempotency_key','booking_id','unit_id','deduction_type','amount','description','external_reference','created_at')");
        DB::statement("CREATE TRIGGER fcd_status BEFORE UPDATE ON finance_commission_deductions FOR EACH ROW EXECUTE FUNCTION finance_guard_status('pending_review>approved','pending_review>voided','approved>voided')");
        DB::statement("CREATE TRIGGER fcd_final BEFORE UPDATE ON finance_commission_deductions FOR EACH ROW WHEN (OLD.status <> 'pending_review') EXECUTE FUNCTION finance_guard_columns('approved_at')");
        DB::statement("CREATE TRIGGER fcd_voided BEFORE UPDATE ON finance_commission_deductions FOR EACH ROW WHEN (OLD.status = 'voided') EXECUTE FUNCTION finance_guard_columns('voided_at','void_reason')");
        DB::statement("CREATE TRIGGER fcd_actors BEFORE UPDATE ON finance_commission_deductions FOR EACH ROW EXECUTE FUNCTION finance_guard_actor_columns('recorded_by','approved_by','voided_by')");
    }

    public function down(): void
    {
        // Development rollback only. Never run against a database that holds real finance records.
        Schema::dropIfExists('finance_commission_deductions');
    }
};
