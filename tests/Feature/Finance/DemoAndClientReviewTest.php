<?php

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Finance\ClientReviewItem;
use App\Models\Finance\CommissionPolicy;
use App\Models\Finance\CommissionStatement;
use App\Models\Finance\Payout;
use App\Models\Finance\RevenueAllocation;
use App\Models\Unit;
use App\Models\User;
use App\Services\Finance\ClientReviewService;
use App\Services\Finance\CommissionCalculator;
use App\Services\Finance\CommissionPolicyService;
use App\Services\Finance\CommissionStatementService;
use App\Services\Finance\Demo\FinanceDemoGuard;
use App\Services\Finance\Demo\FinanceDemoScenarios;
use App\Services\Finance\FinanceException;
use App\Services\Finance\FinanceReportService;
use App\Services\Finance\JobCollectionService;
use App\Services\Finance\PayoutService;
use App\Services\Finance\UnitFundLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__ . '/finance_helpers.php';

// Phase 10: demonstration scenarios (built through the real services, in the disposable test database) and the
// Client Review register.

beforeEach(function () {
    Storage::fake('local');
    FinanceDemoGuard::$simulatedDatabaseName = null;
});

afterEach(function () {
    FinanceDemoGuard::$simulatedDatabaseName = null;
});

function dmSeed(): array
{
    return app(FinanceDemoScenarios::class)->seed('demo-password-123');
}

function dmWeek(): CarbonImmutable
{
    return CarbonImmutable::now(config('finance.timezone'))->startOfWeek(CarbonImmutable::MONDAY)->subWeek();
}

function dmUnit(string $prefix): Unit
{
    return Unit::where('name', 'like', $prefix . '%')->firstOrFail();
}

function dmLine(string $unitPrefix): array
{
    $draft = app(CommissionCalculator::class)->draft(dmWeek(), dmUnit($unitPrefix)->id);

    return $draft['lines'][0] ?? ['blockers' => $draft['blockers'], 'blocked' => $draft['blocked_jobs']];
}

// ---------- Scenarios ----------

it('builds the 15,000 baseline: 2,950 expenses, 50 returned, 12,050 base, 7,230 / 4,820, approved with simulated payouts', function () {
    $r = dmSeed();
    $unit = dmUnit('DEMO Unit 1');
    $statement = CommissionStatement::findOrFail($r['scenarios']['baseline']['statement_id']);
    $funds = app(UnitFundLedger::class)->summary(dmWeek(), dmWeek()->addDays(5), $unit->id)->sole();

    expect($statement->status)->toBe('approved')
        ->and((string) $statement->total_collection)->toBe('15000.00')
        ->and((string) $statement->total_expenses)->toBe('2950.00')
        ->and((string) $statement->total_base)->toBe('12050.00')
        ->and((string) $statement->company_amount)->toBe('7230.00')
        ->and((string) $statement->personnel_amount)->toBe('4820.00')
        ->and($funds['issued']->toString())->toBe('3000.00')
        ->and($funds['spent']->toString())->toBe('2950.00')
        ->and($funds['returned']->toString())->toBe('50.00')
        ->and($funds['remaining']->toString())->toBe('0.00')
        ->and(Payout::where('statement_id', $statement->id)->where('status', 'recorded')->pluck('amount')->sort()->values()->all())->toBe(['1205.00', '1446.00'])
        ->and(Payout::where('statement_id', $statement->id)->pluck('external_reference')->every(fn ($ref) => str_starts_with($ref, 'DEMO-SIMULATED')))->toBeTrue()
        ->and(app(CommissionStatementService::class)->verifyIntegrity($statement))->toBeTrue();
});

it('shows the missing Rider (1,205 unallocated), the 7,500 partial payment and the 2,000 personal advance as blocked', function () {
    dmSeed();

    $rider = dmLine('DEMO Unit 2');
    expect($rider['roles']['rider']['unallocated']->toString())->toBe('1205.00')
        ->and(collect($rider['blockers'])->pluck('code'))->toContain('missing_role')
        ->and(CommissionStatement::where('unit_id', dmUnit('DEMO Unit 2')->id)->value('status'))->toBe('for_review');

    $partialJob = app(JobCollectionService::class)->jobFor(Booking::where('assigned_unit_id', dmUnit('DEMO Unit 3')->id)->firstOrFail());
    expect($partialJob['collected']->toString())->toBe('7500.00')
        ->and($partialJob['agreed']->toString())->toBe('15000.00')
        ->and($partialJob['status'])->toBe('partial')
        ->and($partialJob['allocation']['status'])->toBe('pending_review');
    $partialDraft = app(CommissionCalculator::class)->draft(dmWeek(), dmUnit('DEMO Unit 3')->id);
    expect($partialDraft['lines'])->toBe([])->and($partialDraft['can_approve'])->toBeFalse();

    $advance = dmLine('DEMO Unit 6');
    expect(collect($advance['blockers'])->pluck('code'))->toContain('personal_advance_pending')
        ->and(collect($advance['expenses'])->pluck('amount')->map->toString()->all())->toBe(['2000.00']);
});

it('keeps the two-unit job pending with the 50:50 split recorded only as a client review proposal', function () {
    dmSeed();
    $booking = Booking::where('assigned_unit_id', dmUnit('DEMO Unit 4')->id)->firstOrFail();
    $job = app(JobCollectionService::class)->jobFor($booking);

    expect($job['allocation']['status'])->toBe('pending_review')
        ->and(RevenueAllocation::where('booking_id', $booking->id)->exists())->toBeFalse()   // nothing was applied
        ->and(ClientReviewItem::where('title', 'like', "%{$booking->booking_code}%50:50%")->value('status'))->toBe('pending');
});

it('counts the three-vehicle booking once and the whole demo reconciles in the Finance Report', function () {
    dmSeed();
    $group = app(JobCollectionService::class)->jobFor(Booking::where('assigned_unit_id', dmUnit('DEMO Unit 7')->id)->firstOrFail());
    expect($group['vehicles'])->toBe(3)->and($group['collected']->toString())->toBe('15000.00')
        ->and(collect($group['allocation']['by_unit'])->map(fn ($a) => $a['amount']->toString())->sort()->values()->all())->toBe(['4000.00', '11000.00']);

    $r = app(FinanceReportService::class)->build(dmWeek(), dmWeek()->addDays(5));
    // 15,000 + 12,050 + 7,500 (partial) + 15,000 + 10,000 + 15,000 (three vehicles, once)
    expect($r['jobs']['totals']['bookings'])->toBe(6)
        ->and($r['jobs']['totals']['vehicles'])->toBe(8)
        ->and($r['jobs']['totals']['collected']->toString())->toBe('74550.00')
        ->and($r['jobs']['totals']['outstanding']->toString())->toBe('7500.00')
        ->and($r['approved']['personnel']->toString())->toBe('4820.00')
        ->and($r['payouts']['total']->toString())->toBe('2651.00')
        ->and($r['expenses']['summary']['approved_personal']->toString())->toBe('2000.00')
        ->and($r['expenses']['summary']['submitted']['count'])->toBe(1)
        ->and($r['meta']['policy_label'])->toBe('DEMONSTRATION stand-in — not a client confirmation')
        ->and($r['meta']['policy_confirmed'])->toBeFalse();
});

// ---------- Isolation ----------

it('refuses demo seeding, the stand-in policy and its approvals or payouts on a live-like database', function () {
    $r = dmSeed();   // allowed: disposable test database
    $statement = CommissionStatement::findOrFail($r['scenarios']['baseline']['statement_id']);
    $owner = User::where('email', $r['owner_email'])->firstOrFail();

    FinanceDemoGuard::$simulatedDatabaseName = 'towmate_jarz';   // pretend this is the real database
    expect(fn () => app(FinanceDemoScenarios::class)->seed())->toThrow(FinanceException::class, 'Refused')
        ->and(fn () => app(PayoutService::class)->record($owner, $statement, Payout::where('statement_id', $statement->id)->value('recipient_key') === 'x' ? 'x' : 'company', 'cash', now()->toDateString(), (string) Str::uuid(), 'X'))->toThrow(FinanceException::class)
        ->and(fn () => app(CommissionPolicyService::class)->confirmInterpretation($owner, CommissionPolicy::where('version', 1)->first(), FinanceDemoScenarios::STAND_IN, FinanceDemoScenarios::STAND_IN))->toThrow(FinanceException::class, 'Refused');

    // A real payable recipient is refused too: simulated payouts never leave the demo database.
    $key = collect(app(PayoutService::class)->earningsByRecipient($statement))->keys()->first(fn ($k) => ! Payout::where('statement_id', $statement->id)->where('recipient_key', $k)->exists());
    expect(fn () => app(PayoutService::class)->record($owner, $statement, $key, 'cash', now()->toDateString(), (string) Str::uuid(), 'X'))->toThrow(FinanceException::class, 'Refused');

    // Approving another statement under the stand-in is refused as well.
    $second = CommissionStatement::where('status', 'for_review')->firstOrFail();
    expect(fn () => app(CommissionStatementService::class)->approve($owner, $second))->toThrow(FinanceException::class);
});

it('refuses the reset command and hides the demo page unless this is a demo database', function () {
    $owner = finOwner();
    $before = Unit::count() + 1;
    finUnit();

    config(['finance.demo.enabled' => true]);   // enabled, but the test database name does not end with _demo
    $this->artisan('finance:demo-reset', ['--force' => true])->expectsOutputToContain('Refused')->assertFailed();
    expect(Unit::count())->toBe($before);
    $this->actingAs($owner)->get(route('superadmin.finance.demo.index'))->assertNotFound();
    $this->actingAs($owner)->post(route('superadmin.finance.demo.reset'), ['confirm' => 'RESET DEMO'])->assertNotFound();
    $this->actingAs($owner)->get(route('superadmin.finance.overview'))->assertOk()->assertDontSee('data-demo-banner', false);

    config(['finance.demo.enabled' => false]);
    FinanceDemoGuard::$simulatedDatabaseName = 'towmate_finance_demo';
    $this->artisan('finance:demo-reset', ['--force' => true])->assertFailed();   // not enabled
    expect(Unit::count())->toBe($before);
});

it('shows the demo page, banner and document marking on a demo database, to the Owner only', function () {
    $r = dmSeed();
    $owner = User::where('email', $r['owner_email'])->firstOrFail();
    config(['finance.demo.enabled' => true]);
    FinanceDemoGuard::$simulatedDatabaseName = 'towmate_finance_demo';

    $this->actingAs($owner)->get(route('superadmin.finance.demo.index'))->assertOk()
        ->assertSee('Demonstration walkthrough')->assertSee('DEMO Unit 1 — Baseline')->assertSee($r['scenarios']['baseline']['statement'])
        ->assertSee('data-demo-banner', false)->assertSee('Type RESET DEMO');
    $this->actingAs($owner)->get(route('superadmin.finance.statements.print', $r['scenarios']['baseline']['statement_id']))->assertOk()
        ->assertSee('data-doc-demo', false)->assertSee('DEMONSTRATION stand-in — not a client confirmation');
    $this->actingAs($owner)->post(route('superadmin.finance.demo.reset'), ['confirm' => 'wrong'])->assertSessionHasErrors('confirm');
    foreach ([2, 3, 4, 5, 6] as $role) {
        $this->actingAs(finUser($role))->get(route('superadmin.finance.demo.index'))->assertForbidden();
        $this->actingAs(finUser($role))->post(route('superadmin.finance.demo.reset'), ['confirm' => 'RESET DEMO'])->assertForbidden();
    }
});

// ---------- Client Review ----------

it('lists the pending client decisions and records answers without changing any policy', function () {
    $owner = finOwner();
    $c1 = ClientReviewItem::where('topic_code', 'C1')->firstOrFail();
    expect(ClientReviewItem::whereNotNull('topic_code')->count())->toBe(11)
        ->and(ClientReviewItem::where('status', 'pending')->count())->toBe(11);

    $svc = app(ClientReviewService::class);
    expect(fn () => $svc->update($owner, $c1, ['status' => 'answered']))->toThrow(FinanceException::class, 'answer');
    $svc->update($owner, $c1, ['status' => 'answered', 'client_response' => 'Use the net amount after approved expenses; exclude VAT.', 'owner_remarks' => 'Confirmed in the meeting of Nov 12.']);

    $policy = CommissionPolicy::where('version', 1)->first();
    expect($c1->fresh()->status)->toBe('answered')
        ->and($policy->status)->toBe('draft')
        ->and($policy->interpretation_status)->toBe('pending_client_confirmation')    // answering never confirms anything
        ->and(AuditLog::where('action', 'finance_client_review_updated')->first()->new_value['after']['status'])->toBe('answered');

    $revision = $svc->create($owner, ['kind' => 'revision', 'title' => 'Show the plate number on the commission statement', 'requested_revision' => 'Add the unit plate next to the unit name.']);
    expect($revision->status)->toBe('revision_requested');
    \App\Services\Finance\Demo\FinanceDemoGuard::$simulatedDatabaseName = null;
    expect(fn () => $revision->delete())->toThrow(\Illuminate\Database\QueryException::class);
});

it('renders the Client Review page and a downloadable summary for the Owner only', function () {
    $owner = finOwner();
    $this->actingAs($owner)->get(route('superadmin.finance.client-review.index'))->assertOk()
        ->assertSee('Client Review')->assertSee('Commission sharing base')->assertSee('data-cr-count="pending">11<', false)
        ->assertSee('Draft / Pending Client Confirmation');
    $this->actingAs($owner)->post(route('superadmin.finance.client-review.update', ClientReviewItem::where('topic_code', 'C2')->value('id')), [
        'status' => 'in_discussion', 'owner_remarks' => 'Client will decide after checking with the crew.',
    ])->assertSessionHasNoErrors();
    $this->actingAs($owner)->get(route('superadmin.finance.client-review.print'))->assertOk()
        ->assertSee('OWNER FINANCE — CLIENT REVIEW SUMMARY')->assertSee('Client will decide after checking with the crew.')->assertSee('data-crs-count="in_discussion">1<', false);
    $pdf = $this->actingAs($owner)->get(route('superadmin.finance.client-review.pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

    foreach ([2, 3, 4, 5, 6] as $role) {
        $user = finUser($role);
        $this->actingAs($user)->get(route('superadmin.finance.client-review.index'))->assertForbidden();
        $this->actingAs($user)->get(route('superadmin.finance.client-review.pdf'))->assertForbidden();
        $this->actingAs($user)->post(route('superadmin.finance.client-review.store'), ['kind' => 'remark', 'title' => 'x'])->assertForbidden();
        $this->actingAs($user)->post(route('superadmin.finance.client-review.update', 1), ['status' => 'closed'])->assertForbidden();
        expect(fn () => app(ClientReviewService::class)->create($user, ['kind' => 'remark', 'title' => 'x']))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
    }
    expect(ClientReviewItem::count())->toBe(11);
});
