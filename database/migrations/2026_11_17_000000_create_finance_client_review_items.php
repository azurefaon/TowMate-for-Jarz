<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10: Client Review register. Records the client's pending decisions, requested revisions and remarks.
 * Recording an answer here NEVER changes a commission policy or any financial record: a programmer implements an
 * answered decision afterwards. Rows are never deleted (closed instead); every change is audited.
 *
 * The standard pending decisions are inserted here because they are project configuration (like the draft
 * commission policy v1), not sample data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_client_review_items', function (Blueprint $table) {
            $table->id();
            $table->string('reference_code', 40)->unique();
            $table->string('topic_code', 40)->nullable()->unique();     // C1 … C6, week_cutoff, … (null for ad-hoc items)
            $table->string('kind', 20);                                 // decision | revision | remark
            $table->string('title', 200);
            $table->text('question')->nullable();                       // what the client must decide or review
            $table->text('current_behaviour')->nullable();               // what TowMate does now (provisional)
            $table->text('blocks')->nullable();                          // which actions stay blocked until answered
            $table->text('client_response')->nullable();
            $table->text('requested_revision')->nullable();
            $table->text('owner_remarks')->nullable();
            $table->string('status', 30)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE finance_client_review_items ADD CONSTRAINT fcr_kind CHECK (kind IN ('decision', 'revision', 'remark'))");
            DB::statement("ALTER TABLE finance_client_review_items ADD CONSTRAINT fcr_status CHECK (status IN ('pending', 'in_discussion', 'answered', 'revision_requested', 'closed'))");
            DB::statement('CREATE TRIGGER fcr_no_delete BEFORE DELETE ON finance_client_review_items FOR EACH ROW EXECUTE FUNCTION finance_block_delete()');
            DB::statement("CREATE TRIGGER fcr_core BEFORE UPDATE ON finance_client_review_items FOR EACH ROW EXECUTE FUNCTION finance_guard_columns('reference_code','topic_code','kind','created_at')");
        }

        $now = now();
        $items = [
            ['C1', 'Commission sharing base', 'Which amount do the commission percentages apply to? Is VAT removed first? Do all expense categories and funding sources (company-paid, personal advances) reduce the base? Do payment fees and refunds reduce it?',
                'Base = allocated verified collection − approved job expenses (all categories and funding sources) − approved deductions. VAT is not removed.', 'Approval of any commission statement.'],
            ['C2', 'Two or more people in one role', 'Do people who share a role split that role\'s share (pool) or does each receive the full percentage?',
                'Shown as an equal split for review; approval is blocked until the answer is recorded.', 'Approval of statements where a role is shared.'],
            ['C3', 'Empty roles', 'When nobody held a role on a job, does its share stay unallocated, go to the Company, or something else?',
                'The share stays unallocated and approval is blocked (as instructed).', 'Approval of statements with a missing role.'],
            ['C4', 'Rider, substitute and combined roles', 'Who receives the Rider / Substitute share? How is a substitute or a person borrowed from another unit paid? Can one person receive two role shares on one job?',
                'The Owner records each person\'s performed role. Rider, substitute and combined roles block approval until answered.', 'Approval of statements with a Rider, substitute or combined role.'],
            ['C5', 'Group trips and multi-unit jobs', 'Is each vehicle\'s allocated collection the commission base for its unit? Is there a standard split when another unit helps on a job (for example 50:50)?',
                'Fully paid group vehicles are allocated to their own unit. A job helped by another unit stays "allocation pending review" until the Owner records a split. No automatic 50:50.', 'Approval of jobs helped by another unit.'],
            ['C6', 'Corrections after approval and after payment', 'Should a correction void and re-issue the statement (current behaviour) or be an adjustment in a later week? Can a Paid statement ever be reopened?',
                'Approved statements are voided and re-issued; Paid statements are final.', 'Correcting Paid statements.'],
            ['week_cutoff', 'Which date puts a job in a week; Sunday jobs', 'Should the service date or the payment date decide the commission week? Which week do Sunday jobs belong to?',
                'Service date; weeks are Monday–Saturday; Sunday jobs are listed for review and are not in any week.', 'Commission for Sunday jobs; jobs paid in a later week.'],
            ['monthly_allocation', 'Monthly maintenance allocation', 'How are unit / monthly expenses (for example maintenance) spread across jobs or weeks?',
                'Recorded and approved, but not applied to commission; they block approval for that unit and week.', 'Approval for units with monthly expenses in the week.'],
            ['personal_advance', 'Personal advance reimbursement', 'When a crew member pays a job cost personally, how and when are they reimbursed, and does it reduce the commission base?',
                'Recorded as a separate funding split and flagged; no reimbursement or debt is created; approval is blocked.', 'Approval of statements with personal advances.'],
            ['payout_distribution', 'Partial and group payouts', 'Can commission be paid in instalments or advances, or as one combined payout (for example to the Team Leader for the whole crew)?',
                'Only one full payout per person per statement is supported.', 'Partial or combined payouts.'],
            ['refunds_fees', 'Refunds and payment provider fees', 'Who bears online payment fees and refunds? Should they reduce the commission base?',
                'They can be entered as deductions (pending, then approved). A live online payment blocks approval until its fee is recorded.', 'Approval of jobs paid online or refunded.'],
        ];
        foreach ($items as $i => [$code, $title, $question, $current, $blocks]) {
            DB::table('finance_client_review_items')->insert([
                'reference_code' => 'CR-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'topic_code' => $code, 'kind' => 'decision', 'title' => $title, 'question' => $question,
                'current_behaviour' => $current, 'blocks' => $blocks, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Development rollback only.
        Schema::dropIfExists('finance_client_review_items');
    }
};
