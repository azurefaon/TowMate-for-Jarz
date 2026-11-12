<?php

use App\Models\Finance\ClientReviewItem;
use App\Services\Finance\ClientReviewService;
use App\Services\Finance\CommissionStatementService;
use App\Services\Finance\PayoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__ . '/finance_helpers.php';

// Finance documents: a voided statement keeps its approved figures but states no payment obligation, and Client Review
// item titles name each status once. Display only: amounts, snapshot, payouts and history are untouched.

/** One statement per state, each on its own unit. */
function fpdStatements($owner): array
{
    $svc = app(CommissionStatementService::class);
    $pay = app(PayoutService::class);
    $out = ['for_review' => finReviewStatement($owner)['statement']];
    $out['draft'] = $svc->returnToDraft($owner, finReviewStatement($owner)['statement'], 'Back to draft for the document check');
    $out['approved'] = $svc->approve($owner, finReviewStatement($owner)['statement']);
    $paid = $svc->approve($owner, finReviewStatement($owner)['statement']);
    foreach (array_keys($pay->earningsByRecipient($paid)) as $key) {
        $pay->record($owner, $paid, $key, 'cash', now(config('finance.timezone'))->toDateString(), (string) Str::uuid(), 'REF-' . Str::upper(Str::random(6)));
    }
    $out['paid'] = $pay->markPaid($owner, $paid->fresh());
    $out['voided'] = $svc->void($owner, $svc->approve($owner, finReviewStatement($owner)['statement']), 'Voided for the document check');

    return $out;
}

const FPD_VOIDED_LABEL = 'Total personnel commission (voided, not payable)';

it('words a voided statement without any payment obligation, and keeps its approved figures and history', function () {
    Storage::fake('local');
    $owner = finOwner();
    $statement = fpdStatements($owner)['voided'];
    $personnel = \App\Support\Money::of($statement->personnel_amount)->format();

    $html = $this->actingAs($owner)->get(route('superadmin.finance.statements.print', $statement->id))->assertOk()
        ->assertSee('VOIDED — NOT VALID FOR PAYMENT')->assertSee('data-doc-status="voided"', false)
        ->assertSee('This statement is voided, so no payment is due and none can be recorded against it.')
        ->assertDontSee('Remaining payable')->assertDontSee('No payouts recorded yet')->assertDontSee('Total personnel commission payable')
        ->assertDontSee('Payouts are recorded after the Owner pays')->getContent();
    expect(substr_count($html, FPD_VOIDED_LABEL))->toBe(2)   // the summary and the personnel table
        // the original approved snapshot and amounts are still printed
        ->and($html)->toContain('data-doc-total="personnel">' . $personnel . '<')->toContain('data-doc-total="base">')->toContain($statement->approval_hash)
        ->toContain('Voided on')->toContain('Voided for the document check')->toContain('Approved by');
    // nothing stored changed
    expect($statement->fresh()->status)->toBe('voided')->and($statement->fresh()->approval_hash)->toBe($statement->approval_hash);

    $this->actingAs($owner)->get(route('superadmin.finance.statements.pdf', $statement->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('still lists payouts that were voided before the statement was voided', function () {
    Storage::fake('local');
    $owner = finOwner();
    $svc = app(CommissionStatementService::class);
    $pay = app(PayoutService::class);
    $approved = $svc->approve($owner, finReviewStatement($owner)['statement']);
    $key = array_key_first($pay->earningsByRecipient($approved));
    $payout = $pay->record($owner, $approved, $key, 'cash', now(config('finance.timezone'))->toDateString(), (string) Str::uuid(), 'REF-ORIGINAL');
    $pay->void($owner, $payout, 'Recorded on the wrong person');
    $voided = $svc->void($owner, $approved->fresh(), 'Statement voided after its payout was voided');

    $this->actingAs($owner)->get(route('superadmin.finance.statements.print', $voided->id))->assertOk()
        ->assertSee('Voided payouts (kept for the record)')->assertSee($payout->reference_code)->assertSee('Recorded on the wrong person')
        ->assertSee('This statement is voided, so no payment is due')->assertDontSee('Remaining payable');
    expect($payout->fresh()->status)->toBe('voided');
});

it('leaves the Draft, For review, Approved and Paid documents as they were', function () {
    Storage::fake('local');
    $owner = finOwner();
    $s = fpdStatements($owner);
    $print = fn ($statement) => $this->actingAs($owner)->get(route('superadmin.finance.statements.print', $statement->id))->assertOk();

    foreach (['draft', 'for_review'] as $state) {
        $print($s[$state])->assertSee('This statement is not approved. Amounts may change and must not be paid.')
            ->assertSee('Total personnel commission payable')->assertDontSee('voided, not payable')->assertDontSee('Payouts recorded');
    }
    $print($s['approved'])->assertSee('APPROVED')->assertSee('Total personnel commission payable')->assertDontSee('voided, not payable')
        ->assertSee('No payouts recorded yet. Remaining payable: ₱')->assertSee('Payouts are recorded after the Owner pays each person');
    $print($s['paid'])->assertSee('APPROVED AND PAID')->assertSee('Total personnel commission payable')->assertSee('Total paid / remaining payable')
        ->assertSee('/ ₱0.00')->assertDontSee('voided, not payable')->assertSee('Payouts are recorded after the Owner pays each person');

    // every state still produces a real PDF
    foreach ($s as $state => $statement) {
        $pdf = $this->actingAs($owner)->get(route('superadmin.finance.statements.pdf', $statement->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(str_starts_with($pdf->baseResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse ? file_get_contents($pdf->baseResponse->getFile()->getPathname()) : $pdf->getContent(), '%PDF'))->toBeTrue($state);
    }
});

it('names each Client Review status once in the item title, like the screen', function () {
    $owner = finOwner();
    DB::table('finance_client_review_items')->where('topic_code', 'C2')->update(['status' => 'closed']);
    DB::table('finance_client_review_items')->where('topic_code', 'C3')->update(['status' => 'in_discussion']);
    $revision = app(ClientReviewService::class)->create($owner, ['kind' => 'revision', 'title' => 'Show the rate list first', 'requested_revision' => 'Move rates to the top']);

    $html = $this->actingAs($owner)->get(route('superadmin.finance.client-review.print'))->assertOk()->getContent();
    $title = fn (string $ref) => preg_match('/data-crs-item="' . $ref . '".*?<td colspan="2">(.*?)<\/td>/s', $html, $m) ? html_entity_decode(trim($m[1])) : null;

    expect($title('CR-001'))->toBe('CR-001 — Commission sharing base (Pending client decision)')                // kind equals status: once
        ->and($title('CR-002'))->toBe('CR-002 — Two or more people in one role (Pending client decision; Closed)')   // different: both
        ->and($title('CR-003'))->toBe('CR-003 — Empty roles (Pending client decision; In discussion)')
        ->and($title($revision->reference_code))->toBe($revision->reference_code . ' — Show the rate list first (Requested revision; Revision requested)')
        ->and($html)->not->toContain('Pending client decision; Pending client decision')
        ->and(substr_count($html, 'data-crs-item='))->toBe(ClientReviewItem::count());
    // the stored items, statuses and history are untouched, and the PDF still renders
    expect(ClientReviewItem::count())->toBe(12);
    $this->actingAs($owner)->get(route('superadmin.finance.client-review.pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});
