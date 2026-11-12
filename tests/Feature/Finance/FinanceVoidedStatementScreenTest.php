<?php

use App\Models\Finance\Payout;
use App\Services\Finance\CommissionStatementService;
use App\Services\Finance\PayoutService;
use App\Support\Money;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__ . '/finance_helpers.php';

// The Commission Statement detail page, by state. A voided statement keeps its original approved figures and its
// history but states no payment obligation; Draft, For review, Approved and Paid keep their existing wording.

/** One statement per state, each on its own unit. */
function fvsStatements($owner): array
{
    $svc = app(CommissionStatementService::class);
    $pay = app(PayoutService::class);
    $out = ['for_review' => finReviewStatement($owner)['statement']];
    $out['draft'] = $svc->returnToDraft($owner, finReviewStatement($owner)['statement'], 'Back to draft for the screen check');
    $out['approved'] = $svc->approve($owner, finReviewStatement($owner)['statement']);
    $paid = $svc->approve($owner, finReviewStatement($owner)['statement']);
    foreach (array_keys($pay->earningsByRecipient($paid)) as $key) {
        $pay->record($owner, $paid, $key, 'cash', now(config('finance.timezone'))->toDateString(), (string) Str::uuid(), 'REF-' . Str::upper(Str::random(6)));
    }
    $out['paid'] = $pay->markPaid($owner, $paid->fresh());
    $out['voided'] = $svc->void($owner, $svc->approve($owner, finReviewStatement($owner)['statement']), 'Voided for the screen check');

    return $out;
}

it('words a voided statement without any payment obligation, and keeps the original amounts and details', function () {
    Storage::fake('local');
    $owner = finOwner();
    $statement = fvsStatements($owner)['voided'];
    $original = Money::of($statement->personnel_amount)->format();
    $when = $statement->voided_at->setTimezone(config('app.timezone'))->format('M j, Y g:i A');

    $html = $this->actingAs($owner)->get(route('superadmin.finance.statements.show', $statement->id))->assertOk()
        // the two cards named in the request, and their wording
        ->assertSee('Original crew commission (Voided)')->assertSee('No active amount payable')->assertSee('Payouts recorded')
        ->assertDontSee('Crew payable')->assertDontSee('Recorded / remaining')->assertDontSee('Remaining ₱')
        // nothing says a payout can still be recorded or is still owed
        ->assertDontSee('Record payout')->assertDontSee('Mark statement Paid')->assertDontSee('Record each payment after you have paid the person')
        ->assertDontSee('No payout recorded for')->assertDontSee('data-po-issue', false)
        ->assertSee('This statement is voided, so no payouts can be recorded.')->assertSee('None (statement voided)')
        // status, date and reason are shown clearly
        ->assertSee('fin-status fin-status--red">Voided', false)->assertSee('data-statement-voided', false)
        ->assertSee('Voided for the screen check')->assertSee('No payment is due on this statement.')->getContent();
    expect($html)->toContain($when)
        // the original approved amount, snapshot, approval and integrity details are all still there
        ->toContain('data-po-total="payable">' . $original . '<')->toContain('data-st-total="collection">')->toContain('data-st-total="expenses">')
        ->toContain($statement->approval_hash)->toContain('Integrity verified')->toContain('Approved by')
        ->not->toContain('fin-help fin-negative')                                  // the old small-print line is replaced by the notice
        ->and(substr_count($html, 'Voided for the screen check'))->toBe(2);          // the notice, and the audit history (kept)

    // viewing changes nothing stored
    expect($statement->fresh()->status)->toBe('voided')->and($statement->fresh()->approval_hash)->toBe($statement->approval_hash)
        ->and(Money::of($statement->fresh()->personnel_amount)->format())->toBe($original);
});

it('keeps the payout history of a voided statement', function () {
    Storage::fake('local');
    $owner = finOwner();
    $svc = app(CommissionStatementService::class);
    $pay = app(PayoutService::class);
    $approved = $svc->approve($owner, finReviewStatement($owner)['statement']);
    $key = array_key_first($pay->earningsByRecipient($approved));
    $payout = $pay->record($owner, $approved, $key, 'cash', now(config('finance.timezone'))->toDateString(), (string) Str::uuid(), 'REF-KEEP');
    $pay->void($owner, $payout, 'Recorded on the wrong person');
    $voided = $svc->void($owner, $approved->fresh(), 'Statement voided after its payout was voided');
    $before = Payout::count();

    $this->actingAs($owner)->get(route('superadmin.finance.statements.show', $voided->id))->assertOk()
        ->assertSee('Voided payouts (1)')->assertSee('data-po-voided="' . $payout->reference_code . '"', false)->assertSee('Recorded on the wrong person')
        ->assertSee('Original crew commission (Voided)')->assertSee('No active amount payable')->assertDontSee('Recorded / remaining');
    expect(Payout::count())->toBe($before)->and($payout->fresh()->status)->toBe('voided');
});

it('leaves the Draft, For review, Approved and Paid statement pages as they were', function () {
    Storage::fake('local');
    $owner = finOwner();
    $s = fvsStatements($owner);
    $show = fn ($statement) => $this->actingAs($owner)->get(route('superadmin.finance.statements.show', $statement->id))->assertOk();

    foreach (['draft', 'for_review'] as $state) {   // no payouts panel before approval
        $show($s[$state])->assertDontSee('Original crew commission (Voided)')->assertDontSee('data-statement-voided', false)->assertDontSee('Crew payable');
    }
    $show($s['approved'])->assertSee('Crew payable')->assertSee('Recorded / remaining')->assertSee('Remaining ₱')->assertSee('No payout recorded for')
        ->assertSee('fin-status fin-status--grey">Not recorded', false)->assertSee('Record payout')->assertSee('data-po-mark-paid', false)
        ->assertSee('Record each payment after you have paid the person')
        ->assertDontSee('Original crew commission (Voided)')->assertDontSee('No active amount payable')->assertDontSee('data-statement-voided', false)->assertDontSee('None (statement voided)');
    $show($s['paid'])->assertSee('Crew payable')->assertSee('Recorded / remaining')->assertSee('Remaining ₱0.00')
        ->assertSee('Every payout is recorded and the statement is Paid, which is final.')->assertSee('fin-status fin-status--green">Recorded', false)
        ->assertDontSee('Original crew commission (Voided)')->assertDontSee('No active amount payable')->assertDontSee('data-statement-voided', false);
});

it('keeps the statement page Owner-only for every state', function () {
    Storage::fake('local');
    $owner = finOwner();
    $s = fvsStatements($owner);
    foreach ([2, 3, 4, 5, 6] as $role) {
        $user = finUser($role);
        foreach ($s as $statement) {
            $this->actingAs($user)->get(route('superadmin.finance.statements.show', $statement->id))->assertForbidden();
        }
    }
    // a voided statement offers no write route to anyone: recording a payout is refused outright
    $voided = $s['voided'];
    $key = array_key_first(app(PayoutService::class)->earningsByRecipient($voided->fresh()) ?: ['x' => 0]);
    $this->actingAs($owner)->post(route('superadmin.finance.payouts.store', $voided->id), [
        'idempotency_key' => (string) Str::uuid(), 'recipient_key' => $key, 'paid_at' => now()->toDateString(), 'payout_method' => 'cash', 'external_reference' => 'REF-NO',
    ])->assertSessionHasErrors();
    expect(Payout::where('statement_id', $voided->id)->where('status', 'recorded')->count())->toBe(0);
});
