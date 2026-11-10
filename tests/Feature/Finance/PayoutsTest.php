<?php

use App\Models\AuditLog;
use App\Models\Finance\CommissionStatement;
use App\Models\Finance\JobPersonnel;
use App\Models\Finance\Payout;
use App\Models\User;
use App\Services\Finance\CommissionPolicyService;
use App\Services\Finance\CommissionStatementService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceException;
use App\Services\Finance\JobCrewService;
use App\Services\Finance\PayoutService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__ . '/finance_helpers.php';

// Phase 7: payout RECORDS. Nothing here moves money. Approvals use the TEST-only confirmed policy fixture in the
// disposable test database; no client decision is treated as confirmed for real data.

beforeEach(function () {
    Storage::fake('local');
});

function poSvc(): PayoutService
{
    return app(PayoutService::class);
}

/** The client baseline, approved: 15,000 collected, 2,950 expenses → base 12,050 → Company 7,230, crew 4,820. */
function poBaseline(): array
{
    $owner = User::where('role_id', 1)->first() ?? finOwner();
    if (! \App\Models\Finance\CommissionPolicy::where('status', 'active')->exists()) {
        finActivePolicy($owner);
    }
    $leader = finUser(3);
    $unit = finUnit($leader);
    $booking = finServicedBooking($unit, '15000.00');
    $people = finRecordCrew($owner, $booking, $unit, $leader);
    $expenses = app(ExpenseService::class);
    foreach (['1700.00', '1250.00'] as $amount) {
        $e = $expenses->submit($owner, ['unit_id' => $unit->id, 'expense_scope' => 'job', 'booking_id' => $booking->booking_code,
            'expense_date' => finLastWeek()->addDays(2)->toDateString(), 'category' => 'fuel_diesel', 'description' => 'Diesel', 'amount' => $amount,
            'fundings' => ['unit_fund' => $amount]], (string) Str::uuid(), [UploadedFile::fake()->image('r.jpg', random_int(10, 90), random_int(10, 90))]);
        $expenses->approve($owner, $e);
    }
    $svc = app(CommissionStatementService::class);
    $statement = $svc->approve($owner, $svc->submitForReview($owner, $svc->generate($owner, finLastWeek()->toDateString(), $unit->id)));

    return ['owner' => $owner, 'statement' => $statement, 'unit' => $unit, 'booking' => $booking] + $people;
}

function poKeys(array $b): array
{
    return [
        'team_leader' => "user:{$b['teamLeader']->id}",
        'driver' => "personnel:{$b['driver']->id}",
        'rider' => "personnel:{$b['rider']->id}",
        'crew' => "personnel:{$b['crew']->id}",
    ];
}

function poPay(User $owner, CommissionStatement $s, string $key, string $ref = null, string $method = 'gcash'): Payout
{
    return poSvc()->record($owner, $s, $key, $method, now()->toDateString(), (string) Str::uuid(), $ref ?? 'REF-' . Str::random(6));
}

// ---------- Baseline and separation of company earnings ----------

it('pays only the crew share of the baseline: 4,820.00 of a 12,050.00 base, never the 7,230.00 company share', function () {
    $b = poBaseline();
    $s = $b['statement'];
    $rec = poSvc()->reconciliation($s);

    expect($rec['base']->toString())->toBe('12050.00')
        ->and($rec['company']->toString())->toBe('7230.00')
        ->and($rec['payable']->toString())->toBe('4820.00')
        ->and(collect($rec['rows'])->mapWithKeys(fn ($r) => [$r['recipient_name'] => $r['earned']->toString()])->sort()->values()->all())
        ->toBe(['964.00', '1205.00', '1205.00', '1446.00'])
        ->and(fn () => poPay($b['owner'], $s, 'company'))->toThrow(FinanceException::class, 'no approved earnings');

    foreach (poKeys($b) as $key) {
        poPay($b['owner'], $s, $key);
    }
    $rec = poSvc()->reconciliation($s->fresh());
    expect($rec['paid']->toString())->toBe('4820.00')
        ->and($rec['remaining']->toString())->toBe('0.00')
        ->and($rec['can_mark_paid'])->toBeTrue()
        ->and($s->fresh()->status)->toBe('approved');   // recording never marks Paid

    $paid = poSvc()->markPaid($b['owner'], $s);
    expect($paid->status)->toBe('paid')
        ->and($paid->paid_by)->toBe($b['owner']->id)
        ->and((string) $paid->total_base)->toBe('12050.00')
        ->and(Payout::where('statement_id', $s->id)->where('status', 'recorded')->sum('amount'))->toEqual('4820.00')
        ->and(AuditLog::where('action', 'finance_statement_paid')->where('entity_id', $s->id)->exists())->toBeTrue();
});

it('records the statement, recipient, roles, amount, date, method, reference and recording Owner', function () {
    $b = poBaseline();
    $payout = poPay($b['owner'], $b['statement'], poKeys($b)['driver'], 'GC-778899', 'gcash');

    expect($payout->statement_id)->toBe($b['statement']->id)
        ->and($payout->recipient_name)->toBe($b['driver']->full_name)
        ->and($payout->recipient_roles)->toBe('Driver')
        ->and($payout->amount)->toBe('1446.00')
        ->and($payout->paid_at->toDateString())->toBe(now()->toDateString())
        ->and($payout->methodLabel())->toBe('GCash')
        ->and($payout->external_reference)->toBe('GC-778899')
        ->and($payout->recorded_by)->toBe($b['owner']->id)
        ->and($payout->recorded_by_name)->not->toBeEmpty()
        ->and(AuditLog::find($payout->audit_log_id)->action)->toBe('finance_payout_recorded');
});

// ---------- Restrictions ----------

it('refuses payouts against draft, for-review, voided and paid statements', function () {
    $owner = finOwner();
    ['statement' => $review, 'driver' => $driver] = finReviewStatement($owner);
    $key = "personnel:{$driver->id}";
    expect(fn () => poPay($owner, $review, $key))->toThrow(FinanceException::class, 'approved commission statement');

    $svc = app(CommissionStatementService::class);
    $approved = $svc->approve($owner, $review);
    $svc->void($owner, $approved, 'Prepared for the wrong unit');
    expect(fn () => poPay($owner, $approved->fresh(), $key))->toThrow(FinanceException::class, 'approved commission statement');

    $b = poBaseline();
    foreach (poKeys($b) as $k) {
        poPay($b['owner'], $b['statement'], $k);
    }
    poSvc()->markPaid($b['owner'], $b['statement']);
    expect(fn () => poPay($b['owner'], $b['statement']->fresh(), poKeys($b)['driver']))->toThrow(FinanceException::class, 'already marked Paid')
        ->and(Payout::where('statement_id', $review->id)->count())->toBe(0);
});

it('requires a reference or evidence, a real method, and a payout date within range', function () {
    $b = poBaseline();
    $key = poKeys($b)['driver'];
    $s = $b['statement'];

    expect(fn () => poSvc()->record($b['owner'], $s, $key, 'cash', now()->toDateString(), (string) Str::uuid()))->toThrow(FinanceException::class, 'transaction reference')
        ->and(fn () => poSvc()->record($b['owner'], $s, $key, 'paypal', now()->toDateString(), (string) Str::uuid(), 'X1'))->toThrow(FinanceException::class, 'payout method')
        ->and(fn () => poSvc()->record($b['owner'], $s, $key, 'cash', now()->addDays(2)->toDateString(), (string) Str::uuid(), 'X1'))->toThrow(FinanceException::class, 'future')
        ->and(fn () => poSvc()->record($b['owner'], $s, $key, 'cash', finLastWeek()->subDay()->toDateString(), (string) Str::uuid(), 'X1'))->toThrow(FinanceException::class, 'before the statement period');

    $file = UploadedFile::fake()->create('ack.pdf', 20, 'application/pdf');
    $payout = poSvc()->record($b['owner'], $s, $key, 'cash', now()->toDateString(), (string) Str::uuid(), null, $file);
    expect($payout->external_reference)->toBeNull()
        ->and($payout->evidence_sha256)->toMatch('/^[0-9a-f]{64}$/')
        ->and(Storage::disk('local')->exists($payout->fresh()->evidence_path))->toBeTrue()
        ->and($payout->toArray())->not->toHaveKey('evidence_path');

    $this->actingAs($b['owner'])->get(route('superadmin.finance.payouts.evidence', $payout->id))->assertOk();
    $this->actingAs(finUser(2))->get(route('superadmin.finance.payouts.evidence', $payout->id))->assertForbidden();
    finExpectDbRejects(fn () => DB::table('finance_payouts')->where('id', $payout->id)->update(['evidence_path' => null]), 'evidence_path');
});

it('handles duplicate and concurrent submissions without paying twice', function () {
    $b = poBaseline();
    $key = poKeys($b)['driver'];
    $idem = (string) Str::uuid();

    $first = poSvc()->record($b['owner'], $b['statement'], $key, 'gcash', now()->toDateString(), $idem, 'GC-1');
    $replay = poSvc()->record($b['owner'], $b['statement'], $key, 'gcash', now()->toDateString(), $idem, 'GC-1');
    expect($replay->id)->toBe($first->id)
        ->and(fn () => poSvc()->record($b['owner'], $b['statement'], $key, 'cash', now()->toDateString(), $idem, 'GC-1'))->toThrow(FinanceException::class, 'different details')
        ->and(fn () => poPay($b['owner'], $b['statement'], $key))->toThrow(FinanceException::class, 'already recorded');

    // A concurrent request that slipped past the service check still hits the database unique index.
    finExpectDbRejects(fn () => DB::table('finance_payouts')->insert([
        'reference_code' => 'PAY-X', 'idempotency_key' => (string) Str::uuid(), 'statement_id' => $b['statement']->id, 'recipient_key' => $key,
        'recipient_name' => 'X', 'amount' => '1446.00', 'payout_method' => 'cash', 'external_reference' => 'X', 'paid_at' => now(), 'status' => 'recorded',
        'created_at' => now(), 'updated_at' => now(),
    ]), 'fpo_one_recorded_per_recipient');

    expect(Payout::where('recipient_key', $key)->count())->toBe(1);
});

it('marks Paid only after exact reconciliation, never automatically, and the database enforces it', function () {
    $b = poBaseline();
    $keys = poKeys($b);
    $s = $b['statement'];

    poPay($b['owner'], $s, $keys['driver']);
    poPay($b['owner'], $s, $keys['rider']);
    expect(poSvc()->reconciliation($s)['remaining']->toString())->toBe('2169.00')
        ->and(fn () => poSvc()->markPaid($b['owner'], $s))->toThrow(FinanceException::class, 'cannot be marked Paid yet');
    finExpectDbRejects(fn () => DB::table('finance_commission_statements')->where('id', $s->id)->update(['status' => 'paid', 'paid_at' => now(), 'paid_by_name' => 'X']), 'equal its personnel amount');

    poPay($b['owner'], $s, $keys['team_leader']);
    poPay($b['owner'], $s, $keys['crew']);
    expect($s->fresh()->status)->toBe('approved');
    expect(poSvc()->markPaid($b['owner'], $s)->status)->toBe('paid')
        ->and(fn () => poSvc()->markPaid($b['owner'], $s))->toThrow(FinanceException::class, 'already marked Paid');
});

it('never pays unallocated shares or the company', function () {
    $b = poBaseline();
    expect(fn () => poPay($b['owner'], $b['statement'], 'unallocated'))->toThrow(FinanceException::class, 'no approved earnings')
        ->and(fn () => poPay($b['owner'], $b['statement'], 'company'))->toThrow(FinanceException::class, 'no approved earnings')
        ->and(collect(poSvc()->earningsByRecipient($b['statement']))->keys()->all())->not->toContain('company', 'unallocated');

    finExpectDbRejects(fn () => DB::table('finance_payouts')->insert(['reference_code' => 'PAY-U', 'idempotency_key' => (string) Str::uuid(), 'statement_id' => $b['statement']->id,
        'recipient_key' => 'unallocated', 'recipient_name' => 'Unallocated', 'amount' => '1.00', 'payout_method' => 'cash', 'external_reference' => 'X',
        'paid_at' => now(), 'status' => 'recorded', 'created_at' => now(), 'updated_at' => now()]), 'fpo_not_unallocated');
    // A statement with unallocated amounts can never be approved, so it never reaches payout.
    finExpectDbRejects(fn () => DB::table('finance_commission_statements')->where('id', $b['statement']->id)->update(['unallocated_amount' => '1.00']), 'unallocated_amount');
});

it('pays from the approved snapshot, not from current crew or rates', function () {
    $b = poBaseline();
    $owner = $b['owner'];

    // After approval: the driver record is corrected and new rates take effect.
    $row = JobPersonnel::current()->where('personnel_id', $b['driver']->id)->first();
    $other = finPersonnel('driver');
    app(JobCrewService::class)->correct($owner, $row, ['unit_id' => $b['unit']->id, 'participant' => "personnel:{$other->id}", 'commission_role' => 'driver'], 'Wrong driver on the record');
    $policies = app(CommissionPolicyService::class);
    $v2 = $policies->createDraft($owner, 'v2', ['company' => '40', 'driver' => '30', 'team_leader' => '10', 'rider' => '10', 'crew_mate' => '10']);
    $policies->confirmInterpretation($owner, $v2, 'test_base', 'test_rule', ['rider_role' => 'confirmed']);
    $policies->activate($owner, $v2->fresh(), now()->toDateString());

    $payout = poPay($owner, $b['statement']->fresh(), poKeys($b)['driver']);
    expect($payout->amount)->toBe('1446.00')                      // 12% of 12,050 from the snapshot, not 30%
        ->and($payout->recipient_name)->toBe($b['driver']->full_name)
        ->and(fn () => poPay($owner, $b['statement']->fresh(), "personnel:{$other->id}"))->toThrow(FinanceException::class, 'no approved earnings');
});

it('keeps approved amounts and paid payouts immutable', function () {
    $b = poBaseline();
    $s = $b['statement'];
    $hash = $s->approval_hash;
    $payouts = collect(poKeys($b))->map(fn ($k) => poPay($b['owner'], $s, $k));
    poSvc()->markPaid($b['owner'], $s);
    $paid = $s->fresh();

    finExpectDbRejects(fn () => DB::table('finance_payouts')->where('id', $payouts['driver']->id)->update(['amount' => '1.00']), 'amount');
    finExpectDbRejects(fn () => DB::table('finance_payouts')->where('id', $payouts['driver']->id)->delete(), 'permanent');
    finExpectDbRejects(fn () => DB::table('finance_payouts')->where('id', $payouts['driver']->id)->update(['status' => 'voided', 'voided_at' => now(), 'void_reason' => 'x']), 'Paid statement');
    finExpectDbRejects(fn () => DB::table('finance_commission_statements')->where('id', $s->id)->update(['personnel_amount' => '1.00']), 'personnel_amount');
    finExpectDbRejects(fn () => DB::table('finance_commission_statements')->where('id', $s->id)->update(['status' => 'approved']), 'not allowed');
    finExpectDbRejects(fn () => DB::table('finance_commission_statements')->where('id', $s->id)->update(['paid_at' => now()->subDay()]), 'paid_at');

    expect(fn () => poSvc()->void($b['owner'], $payouts['driver'], 'Wrong person paid'))->toThrow(FinanceException::class, 'final')
        ->and(fn () => app(CommissionStatementService::class)->void($b['owner'], $paid, 'Too late now'))->toThrow(FinanceException::class)
        ->and($paid->approval_hash)->toBe($hash)
        ->and(app(CommissionStatementService::class)->verifyIntegrity($paid))->toBeTrue();
});

it('corrects a payout by voiding it with a reason and recording a linked replacement', function () {
    $b = poBaseline();
    $key = poKeys($b)['driver'];
    $wrong = poPay($b['owner'], $b['statement'], $key, 'GC-WRONG', 'gcash');

    expect(fn () => poSvc()->correct($b['owner'], $wrong, '', 'bank_transfer', now()->toDateString(), (string) Str::uuid(), 'BDO-1'))->toThrow(FinanceException::class, 'reason');
    $new = poSvc()->correct($b['owner'], $wrong, 'Paid by bank, not GCash', 'bank_transfer', now()->toDateString(), (string) Str::uuid(), 'BDO-12345');
    $old = $wrong->fresh();

    expect($old->status)->toBe('voided')
        ->and($old->void_reason)->toContain('Paid by bank, not GCash')
        ->and($old->external_reference)->toBe('GC-WRONG')
        ->and($new->corrects_payout_id)->toBe($old->id)
        ->and($new->amount)->toBe('1446.00')
        ->and($new->methodLabel())->toBe('Bank Transfer')
        ->and(Payout::where('recipient_key', $key)->count())->toBe(2)
        ->and(AuditLog::whereIn('action', ['finance_payout_voided', 'finance_payout_corrected', 'finance_payout_recorded'])->count())->toBe(4);

    expect(fn () => poSvc()->void($b['owner'], $old, 'Again please'))->toThrow(FinanceException::class, 'already voided');
    $this->actingAs($b['owner'])->get(route('superadmin.finance.statements.show', $b['statement']->id))
        ->assertOk()->assertSee('data-po-voided="' . $old->reference_code . '"', false)->assertSee($new->reference_code);
});

// ---------- Printable statement ----------

it('prints an accurate English statement with approval and payout details, and renders it to PDF', function () {
    $b = poBaseline();
    $s = $b['statement'];
    $p1 = poPay($b['owner'], $s, poKeys($b)['driver'], 'GC-445566');

    $html = $this->actingAs($b['owner'])->get(route('superadmin.finance.statements.print', $s->id))->assertOk();
    $html->assertSee('WEEKLY COMMISSION STATEMENT')
        ->assertSee('data-doc-ref>' . $s->reference_code . '<', false)
        ->assertSee($b['unit']->name)
        ->assertSee($s->period_start->format('F j, Y') . ' – ' . $s->period_end->format('F j, Y'))
        ->assertSee('data-doc-booking="' . $b['booking']->booking_code . '"', false)
        ->assertSee('data-doc-total="collection">₱15,000.00<', false)
        ->assertSee('data-doc-total="expenses">₱2,950.00<', false)
        ->assertSee('data-doc-total="base">₱12,050.00<', false)
        ->assertSee('data-doc-total="company">₱7,230.00<', false)
        ->assertSee('data-doc-total="personnel">₱4,820.00<', false)
        ->assertSee('data-doc-total="unallocated">₱0.00<', false)
        ->assertSee('data-doc-person="' . $b['driver']->full_name . '|Driver"', false)
        ->assertSee('12%')
        ->assertSee('₱1,446.00')
        ->assertSee('₱964.00')
        ->assertSee('data-doc-status="approved"', false)
        ->assertSee('APPROVED')
        ->assertSee('data-doc-approver>' . $s->approved_by_name, false)
        ->assertSee('data-doc-payout="' . $p1->reference_code . '"', false)
        ->assertSee('GC-445566')
        ->assertSee('Print / Save as PDF')
        ->assertSee('TowMate does not transfer money');

    $pdf = $this->actingAs($b['owner'])->get(route('superadmin.finance.statements.pdf', $s->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');
});

it('marks an unapproved statement as a draft that must not be paid', function () {
    $owner = finOwner();
    ['statement' => $s] = finReviewStatement($owner, confirmPolicy: false);

    $this->actingAs($owner)->get(route('superadmin.finance.statements.print', $s->id))
        ->assertOk()->assertSee('FOR REVIEW — NOT APPROVED')->assertSee('must not be paid')->assertSee('pending client confirmation')
        ->assertDontSee('Payouts recorded');
});

// ---------- Pages and authorization ----------

it('records and marks paid through the Owner pages', function () {
    $b = poBaseline();
    $s = $b['statement'];
    $this->actingAs($b['owner'])->get(route('superadmin.finance.statements.show', $s->id))
        ->assertOk()->assertSee('data-po-total="payable">₱4,820.00<', false)->assertSee('data-po-total="company">₱7,230.00<', false)
        ->assertSee('data-po-mark-paid="disabled"', false);

    foreach (poKeys($b) as $key) {
        $this->actingAs($b['owner'])->post(route('superadmin.finance.payouts.store', $s->id), [
            'recipient_key' => $key, 'payout_method' => 'cash', 'paid_at' => now()->toDateString(), 'external_reference' => 'ACK-' . Str::random(4),
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
    }
    $this->actingAs($b['owner'])->get(route('superadmin.finance.statements.show', $s->id))->assertSee('data-po-mark-paid="enabled"', false);
    $this->actingAs($b['owner'])->post(route('superadmin.finance.payouts.mark-paid', $s->id))->assertSessionHasNoErrors();

    expect($s->fresh()->status)->toBe('paid');
    $this->actingAs($b['owner'])->get(route('superadmin.finance.statements.show', $s->id))->assertOk()->assertSee('data-po-paid', false);
    $this->actingAs($b['owner'])->get(route('superadmin.finance.statements.index'))->assertSee('Paid');
});

it('refuses payout recording, payout changes and statement printing to every non-Owner role', function () {
    $b = poBaseline();
    $s = $b['statement'];
    $payout = poPay($b['owner'], $s, poKeys($b)['driver']);

    foreach ([2, 3, 4, 5, 6] as $role) {
        $user = finUser($role);
        $this->actingAs($user)->post(route('superadmin.finance.payouts.store', $s->id), ['recipient_key' => poKeys($b)['rider'], 'payout_method' => 'cash',
            'paid_at' => now()->toDateString(), 'external_reference' => 'X', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->actingAs($user)->post(route('superadmin.finance.payouts.void', $payout->id), ['reason' => 'Not allowed'])->assertForbidden();
        $this->actingAs($user)->post(route('superadmin.finance.payouts.mark-paid', $s->id))->assertForbidden();
        $this->actingAs($user)->get(route('superadmin.finance.statements.print', $s->id))->assertForbidden();
        $this->actingAs($user)->get(route('superadmin.finance.statements.pdf', $s->id))->assertForbidden();

        expect(fn () => poPay($user, $s, poKeys($b)['rider']))->toThrow(AuthorizationException::class)
            ->and(fn () => poSvc()->void($user, $payout, 'Not allowed'))->toThrow(AuthorizationException::class)
            ->and(fn () => poSvc()->markPaid($user, $s))->toThrow(AuthorizationException::class);
    }

    expect(Payout::count())->toBe(1)->and($payout->fresh()->status)->toBe('recorded')->and($s->fresh()->status)->toBe('approved');
});

// ---------- Phase 6 review: crew without login accounts ----------

it('records Driver, Rider / Substitute and Crew Mate from personnel records without any login account', function () {
    $owner = finOwner();
    $unit = finUnit();
    $booking = finServicedBooking($unit);
    $driver = finPersonnel('driver');
    $rider = finPersonnel('driver');
    $mate = finPersonnel('crew');
    $usersBefore = User::count();

    $options = app(JobCrewService::class)->participantOptions();
    expect($options)->toHaveKeys(["personnel:{$driver->id}", "personnel:{$rider->id}", "personnel:{$mate->id}"]);

    foreach ([[$driver, 'driver'], [$rider, 'rider'], [$mate, 'crew_mate']] as [$person, $role]) {
        $this->actingAs($owner)->post(route('superadmin.finance.crew.store', $booking->id), ['unit_id' => $unit->id, 'participant' => "personnel:{$person->id}", 'commission_role' => $role])
            ->assertSessionHasNoErrors();
    }

    $rows = JobPersonnel::current()->where('booking_id', $booking->id)->orderBy('id')->get();
    expect($rows->pluck('commission_role')->all())->toBe(['driver', 'rider', 'crew_mate'])
        ->and($rows->pluck('user_id')->filter()->all())->toBe([])
        ->and($rows->pluck('personnel_id')->all())->toBe([$driver->id, $rider->id, $mate->id])
        ->and(User::count())->toBe($usersBefore);

    $this->actingAs($owner)->get(route('superadmin.finance.crew.show', $booking->id))
        ->assertOk()->assertSee('value="personnel:' . $mate->id . '"', false)->assertSee($rider->full_name);
});
