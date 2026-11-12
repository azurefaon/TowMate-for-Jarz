<?php

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Finance\CommissionLine;
use App\Models\Finance\CommissionSettlement;
use App\Models\Finance\CommissionStatement;
use App\Models\Finance\Expense;
use App\Models\Finance\JobPersonnel;
use App\Models\PaymentTransaction;
use App\Models\Unit;
use App\Models\User;
use App\Services\Finance\CommissionCalculator;
use App\Services\Finance\CommissionPolicyService;
use App\Services\Finance\CommissionStatementService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceException;
use App\Services\Finance\JobCrewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__ . '/finance_helpers.php';

// Phase 6: crew recording and weekly commission statements. Every fixture lives in the disposable test database;
// the "confirmed" policy used for successful approvals is a TEST stand-in for the client's pending decisions.

beforeEach(function () {
    Storage::fake('local');
});

function stSvc(): CommissionStatementService
{
    return app(CommissionStatementService::class);
}

function stCrew(): JobCrewService
{
    return app(JobCrewService::class);
}

function stGenerate(User $owner, Unit $unit, ?array $jobs = null): CommissionStatement
{
    return stSvc()->generate($owner, finLastWeek()->toDateString(), $unit->id, $jobs);
}

function stExpense(User $owner, Booking $booking, Unit $unit, string $amount, bool $approve = true): Expense
{
    $service = app(ExpenseService::class);
    $expense = $service->submit($owner, [
        'unit_id' => $unit->id, 'expense_scope' => 'job', 'booking_id' => $booking->booking_code,
        'expense_date' => finLastWeek()->addDays(2)->toDateString(), 'category' => 'fuel_diesel', 'description' => 'Diesel',
        'amount' => $amount, 'fundings' => ['unit_fund' => $amount],
    ], (string) Str::uuid(), [UploadedFile::fake()->image('r.jpg', random_int(10, 90), random_int(10, 90))]);

    return $approve ? $service->approve($owner, $expense) : $expense;
}

function stCodes(array $blockers): array
{
    return collect($blockers)->pluck('code')->unique()->values()->all();
}

// ---------- Part 1: crew recording ----------

it('records the actual crew for a job, unit and performed role with an audit trail', function () {
    $owner = finOwner();
    $leader = finUser(3);
    $unit = finUnit($leader);
    $booking = finServicedBooking($unit);
    $driver = finPersonnel('driver');

    $row = stCrew()->record($owner, $booking, ['unit_id' => $unit->id, 'participant' => "personnel:{$driver->id}", 'commission_role' => 'driver', 'notes' => 'Drove the flatbed']);
    $tl = stCrew()->record($owner, $booking, ['unit_id' => $unit->id, 'participant' => "user:{$leader->id}", 'commission_role' => 'team_leader']);

    expect($row->booking_id)->toBe($booking->id)
        ->and($row->unit_id)->toBe($unit->id)
        ->and($row->commission_role)->toBe('driver')
        ->and($row->personnel_id)->toBe($driver->id)
        ->and($row->name_snapshot)->toBe($driver->full_name)
        ->and($row->source)->toBe('manual')
        ->and($row->confirmed_by)->toBe($owner->id)
        ->and($row->needs_review)->toBeFalse()
        ->and($tl->user_id)->toBe($leader->id)
        ->and(AuditLog::where('action', 'finance_crew_recorded')->count())->toBe(2);
});

it('never creates crew by itself: it shows the Dispatch-history crew for the Owner to confirm, never today\'s roster', function () {
    $owner = finOwner();
    $leader = finUser(3);
    $unit = finUnit($leader);
    $rosterDriver = finPersonnel('driver');
    $unit->forceFill(['driver_name' => $rosterDriver->full_name, 'driver_personnel_id' => $rosterDriver->id])->save();
    $booking = finServicedBooking($unit, '1000.00', ['assigned_team_leader_id' => $leader->id, 'driver_name' => 'Booking Driver']);

    $this->actingAs($owner)->get(route('superadmin.finance.crew.show', $booking->id))
        ->assertOk()
        ->assertSee('Names written on the booking')
        ->assertSee('Booking Driver')
        ->assertDontSee("Today's unit roster", false)
        ->assertSee('Crew found in the Dispatch history')
        ->assertSee('data-crew-history="team_leader"', false)
        ->assertSee($leader->full_name ?: $leader->name);

    expect(JobPersonnel::count())->toBe(0)
        ->and(stCodes(app(CommissionCalculator::class)->draft(finLastWeek())['blockers']))->toContain('no_personnel');
});

it('keeps historical attribution when the unit crew changes after the job', function () {
    $owner = finOwner();
    finActivePolicy($owner);
    $unit = finUnit();
    $booking = finServicedBooking($unit);
    ['driver' => $driver] = finRecordCrew($owner, $booking, $unit);

    // Later, the unit gets a different driver and the old driver leaves.
    $newDriver = finPersonnel('driver');
    $unit->forceFill(['driver_name' => $newDriver->full_name, 'driver_personnel_id' => $newDriver->id])->save();
    $driver->forceFill(['personnel_status' => 'inactive'])->save();

    $line = app(CommissionCalculator::class)->draft(finLastWeek())['lines'][0];
    expect($line['roles']['driver']['recipients'][0]['personnel_id'])->toBe($driver->id)
        ->and($line['roles']['driver']['recipients'][0]['name'])->toBe($driver->full_name);
});

it('corrects and removes crew records only with a reason, keeping the superseded history', function () {
    $owner = finOwner();
    $unit = finUnit();
    $booking = finServicedBooking($unit);
    $wrong = finPersonnel('driver');
    $right = finPersonnel('driver');
    $row = stCrew()->record($owner, $booking, ['unit_id' => $unit->id, 'participant' => "personnel:{$wrong->id}", 'commission_role' => 'driver']);

    expect(fn () => stCrew()->correct($owner, $row, ['unit_id' => $unit->id, 'participant' => "personnel:{$right->id}", 'commission_role' => 'driver'], ''))
        ->toThrow(FinanceException::class, 'reason');

    $new = stCrew()->correct($owner, $row, ['unit_id' => $unit->id, 'participant' => "personnel:{$right->id}", 'commission_role' => 'driver'], 'Wrong driver was entered');
    $old = $row->fresh();

    expect($old->superseded_at)->not->toBeNull()
        ->and($old->superseded_by_id)->toBe($new->id)
        ->and($old->supersede_reason)->toBe('Wrong driver was entered')
        ->and($old->superseded_by_user_id)->toBe($owner->id)
        ->and($new->change_reason)->toBe('Wrong driver was entered')
        ->and(JobPersonnel::current()->where('booking_id', $booking->id)->pluck('personnel_id')->all())->toBe([$right->id]);

    expect(fn () => stCrew()->correct($owner, $old, ['unit_id' => $unit->id, 'participant' => "personnel:{$wrong->id}", 'commission_role' => 'driver'], 'Again please'))
        ->toThrow(FinanceException::class, 'already corrected');
    finExpectDbRejects(fn () => DB::table('finance_job_personnel')->where('id', $old->id)->update(['supersede_reason' => 'rewritten']), 'supersede_reason');
    finExpectDbRejects(fn () => DB::table('finance_job_personnel')->where('id', $new->id)->delete(), 'permanent');

    stCrew()->remove($owner, $new, 'Did not work this job');
    expect(JobPersonnel::current()->where('booking_id', $booking->id)->count())->toBe(0)
        ->and(JobPersonnel::where('booking_id', $booking->id)->count())->toBe(2)
        ->and(AuditLog::whereIn('action', ['finance_crew_corrected', 'finance_crew_removed'])->count())->toBe(2);

    $this->actingAs($owner)->get(route('superadmin.finance.crew.show', $booking->id))
        ->assertOk()->assertSee('Corrected and removed records')->assertSee('Wrong driver was entered')->assertSee('Did not work this job');
});

it('rejects crew records without a role, for another unit, duplicated, or for unfinished jobs', function () {
    $owner = finOwner();
    $unit = finUnit();
    $other = finUnit();
    $booking = finServicedBooking($unit);
    $person = finPersonnel('driver');
    $base = ['unit_id' => $unit->id, 'participant' => "personnel:{$person->id}"];

    expect(fn () => stCrew()->record($owner, $booking, $base + ['commission_role' => '']))->toThrow(FinanceException::class, 'role')
        ->and(fn () => stCrew()->record($owner, $booking, ['unit_id' => $other->id, 'participant' => "personnel:{$person->id}", 'commission_role' => 'driver']))->toThrow(FinanceException::class, 'unit that worked')
        ->and(fn () => stCrew()->record($owner, $booking, ['unit_id' => $unit->id, 'participant' => 'user:' . finUser(4)->id, 'commission_role' => 'driver']))->toThrow(FinanceException::class, 'Team Leader accounts');

    stCrew()->record($owner, $booking, $base + ['commission_role' => 'driver']);
    expect(fn () => stCrew()->record($owner, $booking, $base + ['commission_role' => 'driver']))->toThrow(FinanceException::class, 'already recorded');

    $unfinished = finServicedBooking($unit, '500.00', ['status' => 'in_progress', 'completed_at' => null]);
    expect(fn () => stCrew()->record($owner, $unfinished, $base + ['commission_role' => 'crew_mate']))->toThrow(FinanceException::class);
    expect(JobPersonnel::count())->toBe(1);
});

it('requires Owner confirmation of a captured record that needs review', function () {
    $owner = finOwner();
    finActivePolicy($owner);
    $unit = finUnit();
    $booking = finServicedBooking($unit);
    finRecordCrew($owner, $booking, $unit, withRider: false);
    $captured = JobPersonnel::create(['booking_id' => $booking->id, 'unit_id' => $unit->id, 'slot' => 'driver_2', 'commission_role' => 'rider',
        'personnel_id' => finPersonnel('driver')->id, 'name_snapshot' => 'Captured Rider', 'source' => 'backfill', 'needs_review' => true, 'captured_at' => now()]);

    expect(stCodes(app(CommissionCalculator::class)->draft(finLastWeek())['blockers']))->toContain('personnel_needs_review');

    stCrew()->confirm($owner, $captured);
    expect($captured->fresh()->needs_review)->toBeFalse()
        ->and($captured->fresh()->confirmed_by)->toBe($owner->id)
        ->and(app(CommissionCalculator::class)->draft(finLastWeek())['blockers'])->toBe([]);
    finExpectDbRejects(fn () => DB::table('finance_job_personnel')->where('id', $captured->id)->update(['needs_review' => true]), 'needs_review');
});

it('blocks approvable calculations for unconfirmed rider, substitute, shared and combined roles', function () {
    $owner = finOwner();
    finActivePolicy($owner, []);   // confirmed and active, but none of the role answers recorded
    $unit = finUnit();
    $helper = finUnit();
    $booking = finServicedBooking($unit);
    ['teamLeader' => $leader] = finRecordCrew($owner, $booking, $unit);
    stCrew()->record($owner, $booking, ['unit_id' => $unit->id, 'participant' => "user:{$leader->id}", 'commission_role' => 'driver']);        // combined roles
    stCrew()->record($owner, $booking, ['unit_id' => $unit->id, 'participant' => 'personnel:' . finPersonnel('crew')->id, 'commission_role' => 'crew_mate', 'is_substitute' => true]); // shared + substitute

    $draft = app(CommissionCalculator::class)->draft(finLastWeek());
    expect(stCodes($draft['blockers']))->toContain('rider_role_pending')
        ->toContain('substitute_rule_pending')
        ->toContain('shared_role_rule_pending')
        ->toContain('combined_role_rule_pending')
        ->and($draft['can_approve'])->toBeFalse();
    unset($helper);
});

it('clears the role blockers only when a confirmed policy records each answer explicitly', function () {
    $owner = finOwner();
    finActivePolicy($owner, ['rider_role' => 'confirmed', 'substitutes' => 'paid_in_recorded_role', 'shared_role' => 'split_equally', 'combined_roles' => 'paid_per_role']);
    $unit = finUnit();
    $booking = finServicedBooking($unit, '1000.00');
    ['teamLeader' => $leader] = finRecordCrew($owner, $booking, $unit);
    stCrew()->record($owner, $booking, ['unit_id' => $unit->id, 'participant' => "user:{$leader->id}", 'commission_role' => 'driver']);
    stCrew()->record($owner, $booking, ['unit_id' => $unit->id, 'participant' => 'personnel:' . finPersonnel('crew')->id, 'commission_role' => 'crew_mate', 'is_substitute' => true]);

    $draft = app(CommissionCalculator::class)->draft(finLastWeek());
    $line = $draft['lines'][0];
    expect($draft['blockers'])->toBe([])
        ->and($line['roles']['driver']['share']->toString())->toBe('120.00')
        ->and(collect($line['roles']['driver']['recipients'])->pluck('amount')->map->toString()->all())->toBe(['60.00', '60.00'])
        ->and($line['check']->toString())->toBe('1000.00');
});

// ---------- Part 2: statements ----------

it('blocks approval while crew is missing', function () {
    $owner = finOwner();
    finActivePolicy($owner);
    $unit = finUnit();
    finServicedBooking($unit);

    $statement = stSvc()->submitForReview($owner, stGenerate($owner, $unit));
    $review = stSvc()->review($statement);

    expect(stCodes($review['blockers']))->toContain('no_personnel')
        ->and($review['can_approve'])->toBeFalse()
        ->and((string) $statement->fresh()->unallocated_amount)->toBe('400.00')
        ->and(fn () => stSvc()->approve($owner, $statement))->toThrow(FinanceException::class, 'cannot be approved yet');
    expect($statement->fresh()->status)->toBe('for_review')
        ->and(CommissionSettlement::count())->toBe(0);
});

it('moves Draft → For Review → Approved, can return to draft, and refuses skipped steps', function () {
    $owner = finOwner();
    finActivePolicy($owner);
    $unit = finUnit();
    $booking = finServicedBooking($unit);
    finRecordCrew($owner, $booking, $unit);

    $draft = stGenerate($owner, $unit);
    expect($draft->status)->toBe('draft')
        ->and($draft->job_count)->toBe(1)
        ->and((string) $draft->total_base)->toBe('1000.00')
        ->and($draft->line_count)->toBe(5)
        ->and(fn () => stSvc()->approve($owner, $draft))->toThrow(FinanceException::class, 'Submit it for review');

    $review = stSvc()->submitForReview($owner, $draft);
    expect($review->status)->toBe('for_review')
        ->and(fn () => stSvc()->submitForReview($owner, $review))->toThrow(FinanceException::class)
        ->and(fn () => stGenerate($owner, $unit))->toThrow(FinanceException::class, 'For Review');
    finExpectDbRejects(fn () => DB::table('finance_commission_lines')->where('statement_id', $review->id)->update(['amount' => '1.00']), 'locked');

    expect(fn () => stSvc()->returnToDraft($owner, $review, ''))->toThrow(FinanceException::class, 'reason');
    $back = stSvc()->returnToDraft($owner, $review, 'Check the crew again');
    expect($back->status)->toBe('draft')->and($back->return_reason)->toBe('Check the crew again');

    $approved = stSvc()->approve($owner, stSvc()->submitForReview($owner, $back));
    expect($approved->status)->toBe('approved')
        ->and(AuditLog::where('entity_type', 'CommissionStatement')->where('entity_id', $approved->id)->pluck('action')->all())
        ->toBe(['finance_statement_prepared', 'finance_statement_submitted', 'finance_statement_returned', 'finance_statement_submitted', 'finance_statement_approved']);
});

it('keeps a statement under an unconfirmed policy as Draft / For Client Review', function () {
    $owner = finOwner();
    ['statement' => $statement] = finReviewStatement($owner, confirmPolicy: false);

    $review = stSvc()->review($statement);
    expect(stCodes($review['blockers']))->toContain('policy_for_client_review')
        ->and($review['can_approve'])->toBeFalse()
        ->and(fn () => stSvc()->approve($owner, $statement))->toThrow(FinanceException::class, 'client-confirmed');

    $this->actingAs($owner)->get(route('superadmin.finance.statements.show', $statement->id))
        ->assertOk()
        ->assertSee('Draft / For Client Review')
        ->assertSee('data-statement-eligible="no"', false)
        ->assertSee('data-statement-blocker="policy_for_client_review"', false);
});

it('approves a valid statement with an immutable snapshot of jobs, crew, policy, sources, amounts and approver', function () {
    $owner = finOwner();
    finActivePolicy($owner);
    $leader = finUser(3);
    $unit = finUnit($leader);
    $booking = finServicedBooking($unit, '15000.00');
    $people = finRecordCrew($owner, $booking, $unit, $leader);
    $diesel = stExpense($owner, $booking, $unit, '1700.00');
    $food = stExpense($owner, $booking, $unit, '1250.00');

    $statement = stSvc()->approve($owner, stSvc()->submitForReview($owner, stGenerate($owner, $unit)));
    $snap = $statement->approval_snapshot;
    $line = $snap['calculation']['lines'][0];

    expect((string) $statement->total_base)->toBe('12050.00')
        ->and((string) $statement->company_amount)->toBe('7230.00')
        ->and((string) $statement->personnel_amount)->toBe('4820.00')
        ->and((string) $statement->unallocated_amount)->toBe('0.00')
        ->and($snap['statement']['unit_id'])->toBe($unit->id)
        ->and($snap['statement']['included_jobs'])->toBe(['b:' . $booking->id])
        ->and($snap['policy']['rates'])->toBe(['company' => '60.00', 'driver' => '12.00', 'team_leader' => '10.00', 'rider' => '10.00', 'crew_mate' => '8.00'])
        ->and($snap['policy']['interpretation_status'])->toBe('confirmed')
        ->and(collect($line['expenses'])->pluck('id')->all())->toBe([$diesel->id, $food->id])
        ->and($line['collection'])->toBe('15000.00')
        ->and($line['roles']['driver']['recipients'][0]['personnel_id'])->toBe($people['driver']->id)
        ->and($line['roles']['driver']['recipients'][0]['amount'])->toBe('1446.00')
        ->and($line['roles']['team_leader']['recipients'][0]['user_id'])->toBe($leader->id)
        ->and($snap['approval']['approved_by'])->toBe($owner->id)
        ->and($snap['approval']['approved_at'])->toBe($statement->approved_at->format('Y-m-d H:i:s'))
        ->and(collect($snap['audit'])->pluck('action')->all())->toContain('finance_statement_prepared', 'finance_statement_submitted')
        ->and(stSvc()->verifyIntegrity($statement->fresh()))->toBeTrue()
        ->and(CommissionSettlement::active()->where('booking_id', $booking->id)->where('unit_id', $unit->id)->count())->toBe(1)
        ->and(AuditLog::where('action', 'finance_statement_approved')->value('new_value')['approval_hash'])->toBe($statement->approval_hash);
});

it('refuses duplicate approval and a settlement won by another approval at the same time', function () {
    $owner = finOwner();
    finActivePolicy($owner);
    $unit = finUnit();
    $booking = finServicedBooking($unit);
    finRecordCrew($owner, $booking, $unit);
    $statement = stSvc()->submitForReview($owner, stGenerate($owner, $unit));

    // Another approved statement settles the same booking × unit first (simulating a concurrent approval).
    ['statement' => $other] = finReviewStatement($owner);
    $other = stSvc()->approve($owner, $other);
    DB::table('finance_commission_settlements')->insert(['statement_id' => $other->id, 'booking_id' => $booking->id, 'unit_id' => $unit->id,
        'job_key' => 'b:' . $booking->id, 'settled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => stSvc()->approve($owner, $statement))->toThrow(FinanceException::class, 'already settled');
    expect($statement->fresh()->status)->toBe('for_review');

    $approvedOnce = stSvc()->approve($owner, $other->fresh());   // already approved
})->throws(FinanceException::class, 'already approved');

it('never settles the same booking and unit earnings twice, and frees them only through a correction', function () {
    $owner = finOwner();
    ['statement' => $statement, 'booking' => $booking, 'unit' => $unit] = finReviewStatement($owner);
    $approved = stSvc()->approve($owner, $statement);
    $key = 'b:' . $booking->id;

    $candidates = stSvc()->candidates(finLastWeek(), $unit->id)['jobs'];
    expect($candidates[0]['settled_by'])->toBe($approved->reference_code)
        ->and(fn () => stGenerate($owner, $unit))->toThrow(FinanceException::class, 'no unsettled jobs')
        ->and(fn () => stGenerate($owner, $unit, [$key]))->toThrow(FinanceException::class, 'already settled');

    // A second job in the same week can still be settled by a supplementary statement.
    $second = finServicedBooking($unit, '500.00');
    finRecordCrew($owner, $second, $unit);
    $supplement = stGenerate($owner, $unit);
    expect($supplement->included_jobs)->toBe(['b:' . $second->id])->and($supplement->revision)->toBe(2);
});

it('keeps an approved statement unchanged after personnel, policy and expense changes', function () {
    $owner = finOwner();
    ['statement' => $statement, 'booking' => $booking, 'unit' => $unit, 'driver' => $driver] = finReviewStatement($owner);
    $approved = stSvc()->approve($owner, $statement);
    $before = [$approved->approval_hash, (string) $approved->total_base, CommissionLine::where('statement_id', $approved->id)->orderBy('id')->get(['recipient_key', 'amount'])->toArray()];

    // Crew correction after approval.
    $row = JobPersonnel::current()->where('personnel_id', $driver->id)->first();
    $replacement = finPersonnel('driver');
    stCrew()->correct($owner, $row, ['unit_id' => $unit->id, 'participant' => "personnel:{$replacement->id}", 'commission_role' => 'driver'], 'Driver was actually someone else');

    // New rates take effect.
    $policies = app(CommissionPolicyService::class);
    $v2 = $policies->createDraft($owner, 'v2', ['company' => '50', 'driver' => '20', 'team_leader' => '10', 'rider' => '10', 'crew_mate' => '10']);
    $policies->confirmInterpretation($owner, $v2, 'test_base', 'test_rule', ['rider_role' => 'confirmed']);
    $policies->activate($owner, $v2->fresh(), now()->toDateString());

    // A new approved expense on the same job.
    stExpense($owner, $booking, $unit, '100.00');

    $fresh = $approved->fresh();
    expect([$fresh->approval_hash, (string) $fresh->total_base, CommissionLine::where('statement_id', $fresh->id)->orderBy('id')->get(['recipient_key', 'amount'])->toArray()])->toBe($before)
        ->and($fresh->approval_snapshot['calculation']['lines'][0]['roles']['driver']['recipients'][0]['personnel_id'])->toBe($driver->id)
        ->and(stSvc()->verifyIntegrity($fresh))->toBeTrue();

    $this->actingAs($owner)->get(route('superadmin.finance.statements.show', $fresh->id))
        ->assertOk()->assertSee($driver->full_name)->assertDontSee($replacement->full_name)
        ->assertSee('data-statement-integrity="ok"', false)
        ->assertSee('data-st-total="base">₱1,000.00<', false);

    // The live preview reflects the changes; the approved statement does not.
    $preview = app(CommissionCalculator::class)->draft(finLastWeek(), $unit->id)['lines'][0];
    expect($preview['base']->toString())->toBe('900.00')
        ->and($preview['roles']['driver']['recipients'][0]['personnel_id'])->toBe($replacement->id);
});

it('blocks approval for unverified and partly paid collections', function () {
    $owner = finOwner();
    finActivePolicy($owner);
    $unit = finUnit();
    $unverified = finServicedBooking($unit, '1000.00', ['status' => 'waiting_verification', 'completed_at' => null]);
    finRecordCrew($owner, $unverified, $unit);
    $partial = finServicedBooking($unit, '2000.00', ['status' => 'waiting_verification', 'completed_at' => null, 'payment_method' => 'gcash', 'cash_received' => null]);
    PaymentTransaction::create(['provider' => 'paymongo', 'reference' => 'TM-' . Str::random(8), 'payable_type' => 'booking', 'payable_id' => $partial->id,
        'booking_id' => $partial->id, 'customer_id' => $partial->customer_id, 'method' => 'gcash', 'amount_centavos' => 50000, 'status' => 'paid', 'livemode' => true, 'paid_at' => finLastWeek()->addDays(2)]);

    $statement = stSvc()->submitForReview($owner, stGenerate($owner, $unit));
    $review = stSvc()->review($statement);

    expect($statement->job_count)->toBe(2)
        ->and(stCodes($review['blockers']))->toContain('payment_not_verified')->toContain('allocation_pending')
        ->and((string) $statement->total_collection)->toBe('0.00')
        ->and(fn () => stSvc()->approve($owner, $statement))->toThrow(FinanceException::class, 'cannot be approved yet');
});

it('blocks approval for pending expenses and refuses a statement whose sources changed after review', function () {
    $owner = finOwner();
    finActivePolicy($owner);
    $unit = finUnit();
    $booking = finServicedBooking($unit, '5000.00');
    finRecordCrew($owner, $booking, $unit);
    $pending = stExpense($owner, $booking, $unit, '500.00', approve: false);

    $statement = stSvc()->submitForReview($owner, stGenerate($owner, $unit));
    expect(stCodes(stSvc()->review($statement)['blockers']))->toContain('expenses_pending')
        ->and(fn () => stSvc()->approve($owner, $statement))->toThrow(FinanceException::class);

    // The expense is approved after the statement went to review: the reviewed figures are now out of date.
    app(ExpenseService::class)->approve($owner, $pending);
    $review = stSvc()->review($statement->fresh());
    expect($review['stale'])->toBeTrue()
        ->and(stCodes($review['blockers']))->toBe(['calculation_changed'])
        ->and(fn () => stSvc()->approve($owner, $statement))->toThrow(FinanceException::class, 'Source records changed');

    $recalculated = stSvc()->recalculate($owner, stSvc()->returnToDraft($owner, $statement, 'Expense approved meanwhile'));
    $approved = stSvc()->approve($owner, stSvc()->submitForReview($owner, $recalculated));
    expect((string) $approved->total_base)->toBe('4500.00')->and($approved->id)->toBe($statement->id);
});

it('corrects an approved statement by voiding it and re-issuing a linked revision, preserving the original', function () {
    $owner = finOwner();
    ['statement' => $statement, 'booking' => $booking, 'unit' => $unit] = finReviewStatement($owner);
    $original = stSvc()->approve($owner, $statement);
    $hash = $original->approval_hash;

    expect(fn () => stSvc()->correct($owner, $original, ''))->toThrow(FinanceException::class, 'reason');
    $new = stSvc()->correct($owner, $original, 'Wrong crew member was paid');
    $old = $original->fresh();

    expect($old->status)->toBe('voided')
        ->and($old->approval_hash)->toBe($hash)
        ->and($old->void_reason)->toContain('Wrong crew member was paid')
        ->and(stSvc()->verifyIntegrity($old))->toBeTrue()
        ->and(CommissionSettlement::where('statement_id', $old->id)->whereNotNull('released_at')->count())->toBe(1)
        ->and($new->status)->toBe('draft')
        ->and($new->revision)->toBe(2)
        ->and($new->supersedes_statement_id)->toBe($old->id)
        ->and($new->correction_reason)->toBe('Wrong crew member was paid')
        ->and($new->included_jobs)->toBe(['b:' . $booking->id]);

    $reissued = stSvc()->approve($owner, stSvc()->submitForReview($owner, $new));
    expect(CommissionSettlement::active()->where('booking_id', $booking->id)->where('unit_id', $unit->id)->value('statement_id'))->toBe($reissued->id)
        ->and(AuditLog::where('action', 'finance_statement_voided')->where('entity_id', $old->id)->exists())->toBeTrue();

    $this->actingAs($owner)->get(route('superadmin.finance.statements.show', $old->id))
        ->assertOk()->assertSee('Voided')->assertSee($new->reference_code)->assertSee('data-statement-integrity="ok"', false);
});

it('runs the Owner pages end to end: crew, prepare, submit and approve', function () {
    $owner = finOwner();
    finActivePolicy($owner);
    $leader = finUser(3);
    $unit = finUnit($leader);
    $booking = finServicedBooking($unit);
    $driver = finPersonnel('driver');
    $rider = finPersonnel('driver');
    $mate = finPersonnel('crew');

    $this->actingAs($owner)->get(route('superadmin.finance.crew.index', ['week' => finLastWeek()->toDateString()]))
        ->assertOk()->assertSee($booking->booking_code)->assertSee('Not recorded');

    foreach ([["user:{$leader->id}", 'team_leader'], ["personnel:{$driver->id}", 'driver'], ["personnel:{$rider->id}", 'rider'], ["personnel:{$mate->id}", 'crew_mate']] as [$who, $role]) {
        $this->actingAs($owner)->post(route('superadmin.finance.crew.store', $booking->id), ['unit_id' => $unit->id, 'participant' => $who, 'commission_role' => $role])
            ->assertSessionHasNoErrors();
    }
    $this->actingAs($owner)->get(route('superadmin.finance.crew.show', $booking->id))->assertOk()->assertSee($driver->full_name);

    $this->actingAs($owner)->get(route('superadmin.finance.statements.create', ['week' => finLastWeek()->toDateString(), 'unit_id' => $unit->id]))
        ->assertOk()->assertSee('value="b:' . $booking->id . '"', false);
    $this->actingAs($owner)->post(route('superadmin.finance.statements.store'), ['week' => finLastWeek()->toDateString(), 'unit_id' => $unit->id, 'jobs' => ['b:' . $booking->id]])
        ->assertRedirect();
    $statement = CommissionStatement::firstOrFail();

    $this->actingAs($owner)->post(route('superadmin.finance.statements.submit', $statement->id))->assertSessionHasNoErrors();
    $this->actingAs($owner)->get(route('superadmin.finance.statements.show', $statement->id))->assertOk()->assertSee('data-statement-eligible="yes"', false);
    $this->actingAs($owner)->post(route('superadmin.finance.statements.approve', $statement->id))->assertSessionHasNoErrors();

    expect($statement->fresh()->status)->toBe('approved');
    $this->actingAs($owner)->get(route('superadmin.finance.statements.index'))->assertOk()->assertSee($statement->reference_code)->assertSee('Approved');
});

it('refuses crew and statement actions from every non-Owner role, through pages and services', function () {
    $owner = finOwner();
    ['statement' => $statement, 'booking' => $booking, 'unit' => $unit] = finReviewStatement($owner);
    $entry = JobPersonnel::current()->first();

    foreach ([2, 3, 4, 5, 6] as $role) {
        $user = finUser($role);
        $this->actingAs($user)->get(route('superadmin.finance.crew.index'))->assertForbidden();
        $this->actingAs($user)->get(route('superadmin.finance.statements.show', $statement->id))->assertForbidden();
        $this->actingAs($user)->post(route('superadmin.finance.crew.store', $booking->id), ['unit_id' => $unit->id, 'participant' => 'personnel:' . finPersonnel()->id, 'commission_role' => 'crew_mate'])->assertForbidden();
        $this->actingAs($user)->post(route('superadmin.finance.crew.remove', $entry->id), ['reason' => 'Not allowed'])->assertForbidden();
        $this->actingAs($user)->post(route('superadmin.finance.statements.store'), ['week' => finLastWeek()->toDateString(), 'unit_id' => $unit->id, 'jobs' => ['b:' . $booking->id]])->assertForbidden();
        $this->actingAs($user)->post(route('superadmin.finance.statements.approve', $statement->id))->assertForbidden();
        $this->actingAs($user)->post(route('superadmin.finance.statements.return', $statement->id), ['reason' => 'Not allowed'])->assertForbidden();

        expect(fn () => stCrew()->record($user, $booking, ['unit_id' => $unit->id, 'participant' => 'personnel:' . finPersonnel()->id, 'commission_role' => 'crew_mate']))->toThrow(AuthorizationException::class)
            ->and(fn () => stCrew()->remove($user, $entry, 'Not allowed'))->toThrow(AuthorizationException::class)
            ->and(fn () => stSvc()->approve($user, $statement))->toThrow(AuthorizationException::class)
            ->and(fn () => stSvc()->submitForReview($user, $statement))->toThrow(AuthorizationException::class)
            ->and(fn () => stSvc()->correct($user, $statement, 'Not allowed'))->toThrow(AuthorizationException::class);
    }

    expect($statement->fresh()->status)->toBe('for_review')
        ->and(JobPersonnel::current()->count())->toBe(4)
        ->and(CommissionStatement::count())->toBe(1);
});
