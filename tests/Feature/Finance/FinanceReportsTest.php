<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Finance\JobPersonnel;
use App\Models\Quotation;
use App\Models\Unit;
use App\Models\User;
use App\Services\Finance\CommissionStatementService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceReportService;
use App\Services\Finance\FinanceReportWorkbook;
use App\Services\Finance\JobCrewService;
use App\Services\Finance\PayoutService;
use App\Services\Finance\UnitFundService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

require_once __DIR__ . '/finance_helpers.php';

// Phase 9: Owner Finance Reports (page, print/PDF, Excel). Test database only; no sample data enters real reports.

beforeEach(function () {
    Storage::fake('local');
});

function frOwner(): User
{
    return User::where('role_id', 1)->first() ?? finOwner();
}

function frWeek(): array
{
    return [finLastWeek(), finLastWeek()->addDays(5)];
}

function frReport(array $filters = [], ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
{
    [$f, $t] = frWeek();

    return app(FinanceReportService::class)->build($from ?? $f, $to ?? $t, $filters);
}

function frExpense(Booking $booking, Unit $unit, string $category, string $amount, string $source = 'unit_fund', bool $approve = true)
{
    $svc = app(ExpenseService::class);
    $e = $svc->submit(frOwner(), ['unit_id' => $unit->id, 'expense_scope' => 'job', 'booking_id' => $booking->booking_code,
        'expense_date' => finLastWeek()->addDays(2)->toDateString(), 'category' => $category, 'description' => config('finance.expense_categories')[$category],
        'amount' => $amount, 'fundings' => [$source => $amount]], (string) Str::uuid(), [UploadedFile::fake()->image('r.jpg', random_int(10, 90), random_int(10, 90))]);

    return $approve ? $svc->approve(frOwner(), $e) : $e;
}

/** One booking with three vehicles towed by two units: 6,000 + 5,000 (unit A) and 4,000 (unit B). */
function frGroup(Unit $a, Unit $b): array
{
    $customer = Customer::create(['full_name' => 'Group Client', 'phone' => '0917' . random_int(1000000, 9999999), 'email' => 'fr-' . Str::random(6) . '@example.com']);
    $code = 'GRP-' . Str::upper(Str::random(5));
    $at = finLastWeek()->addDays(2)->setTime(9, 0);
    $member = fn (Unit $u, string $total) => Booking::create([
        'customer_id' => $customer->id, 'truck_type_id' => $u->truck_type_id, 'assigned_unit_id' => $u->id, 'group_code' => $code,
        'pickup_address' => 'Group pickup', 'dropoff_address' => 'Group dropoff', 'distance_km' => 30, 'base_rate' => 1500, 'per_km_rate' => 60,
        'computed_total' => $total, 'final_total' => $total, 'payment_method' => 'cash', 'cash_received' => '15000.00',
        'completion_requested_at' => $at, 'payment_submitted_at' => $at, 'status' => 'completed', 'completed_at' => $at->addHour(),
    ]);
    $m1 = $member($a, '6000.00');
    $m2 = $member($a, '5000.00');
    $m3 = $member($b, '4000.00');
    $q = Quotation::create(['quotation_number' => 'QT-' . Str::random(6), 'source_booking_id' => $m1->id, 'customer_id' => $customer->id, 'truck_type_id' => $m1->truck_type_id,
        'pickup_address' => 'Group pickup', 'dropoff_address' => 'Group dropoff', 'distance_km' => 30, 'estimated_price' => '15000', 'additional_fee' => '0', 'discount' => '0',
        'service_type' => 'book_now', 'status' => 'accepted', 'extra_vehicles' => [
            ['booking_id' => $m2->id, 'truck_type_id' => $m2->truck_type_id, 'final_total' => 5000.0],
            ['booking_id' => $m3->id, 'truck_type_id' => $m3->truck_type_id, 'final_total' => 4000.0],
        ]]);
    foreach ([$m1, $m2, $m3] as $m) {
        $m->update(['quotation_id' => $q->id]);
    }

    return [$m1->fresh(), $m2->fresh(), $m3->fresh()];
}

/** The client baseline plus unit funds, with mixed funding so personal advances stay visible. */
function frBaseline(bool $personalAdvance = true): array
{
    $owner = frOwner();
    finActivePolicy($owner);
    $leader = finUser(3);
    $unit = finUnit($leader);
    $booking = finServicedBooking($unit, '15000.00');
    $people = finRecordCrew($owner, $booking, $unit, $leader);
    $funds = app(UnitFundService::class);
    $funds->issue($owner, ['unit_id' => $unit->id, 'issue_kind' => 'initial', 'amount' => '3000', 'entry_date' => finLastWeek()->toDateString(), 'counterparty_name' => 'Tomas'], (string) Str::uuid());
    frExpense($booking, $unit, 'fuel_diesel', '1700.00');
    frExpense($booking, $unit, 'food_travel_allowance', '1000.00', 'company_direct');
    frExpense($booking, $unit, 'toll', '250.00', $personalAdvance ? 'personal_advance' : 'unit_fund');
    $funds->recordReturn($owner, ['unit_id' => $unit->id, 'amount' => '50', 'entry_date' => finLastWeek()->addDays(3)->toDateString(), 'counterparty_name' => 'Tomas'], (string) Str::uuid());

    return ['owner' => $owner, 'unit' => $unit, 'booking' => $booking] + $people;
}

// ---------- Totals and accuracy ----------

it('reports collections, expenses, unit funds and the draft commission with every kind of money kept apart', function () {
    $b = frBaseline();
    $r = frReport();

    expect($r['jobs']['totals']['completed_bookings'])->toBe(1)
        ->and($r['jobs']['totals']['vehicles'])->toBe(1)
        ->and($r['jobs']['totals']['agreed']->toString())->toBe('15000.00')
        ->and($r['jobs']['totals']['collected']->toString())->toBe('15000.00')        // returned 50.00 is not towing income
        ->and($r['jobs']['totals']['outstanding']->toString())->toBe('0.00')
        ->and($r['expenses']['summary']['approved']['total']->toString())->toBe('2950.00') // issued 3,000.00 is not an expense
        ->and($r['expenses']['summary']['approved_personal']->toString())->toBe('250.00')  // personal advance stays distinguishable
        ->and($r['expenses']['summary']['approved_company']->toString())->toBe('1000.00')
        ->and($r['funds']['totals']['issued']->toString())->toBe('3000.00')
        ->and($r['funds']['totals']['spent']->toString())->toBe('1700.00')                 // only unit-fund-paid expenses reduce the fund
        ->and($r['funds']['totals']['returned']->toString())->toBe('50.00')
        ->and($r['funds']['totals']['remaining']->toString())->toBe('1250.00')
        ->and($r['draft']['base']->toString())->toBe('12050.00')
        ->and($r['draft']['company']->toString())->toBe('7230.00')
        ->and($r['draft']['crew']->toString())->toBe('4820.00')
        ->and($r['review']['blockers'])->toHaveKey('personal_advance_pending')             // no reimbursement rule applied
        ->and($r['approved']['count'])->toBe(0)
        ->and($r['payouts']['total']->toString())->toBe('0.00');
    expect(collect($r['expenses']['by_category'])->mapWithKeys(fn ($x) => [$x['key'] => $x['approved']->toString()])->all())
        ->toBe(['food_travel_allowance' => '1000.00', 'fuel_diesel' => '1700.00', 'toll' => '250.00']);
});

it('counts one booking with three vehicles once, never duplicates group cash, and reconciles two-unit allocations', function () {
    [$a, $b] = [finUnit(), finUnit()];
    frGroup($a, $b);
    $r = frReport();
    $t = $r['jobs']['totals'];

    expect($t['bookings'])->toBe(1)
        ->and($t['vehicles'])->toBe(3)
        ->and($t['collected']->toString())->toBe('15000.00')                               // the 15,000 cash on each row counted once
        ->and($t['allocated']->toString())->toBe('15000.00')
        ->and(collect($t['by_unit'])->mapWithKeys(fn ($u) => [$u['unit_id'] => $u['amount']->toString()])->sortKeys()->all())
        ->toBe([$a->id => '11000.00', $b->id => '4000.00'])
        ->and(collect($r['jobs']['rows']))->toHaveCount(1);
});

it('keeps unreviewed expenses out of approved deductions and the draft base', function () {
    $owner = frOwner();
    finActivePolicy($owner);
    $unit = finUnit();
    $booking = finServicedBooking($unit, '5000.00');
    finRecordCrew($owner, $booking, $unit);
    frExpense($booking, $unit, 'repairs', '800.00', approve: false);

    $r = frReport();
    expect($r['expenses']['summary']['approved']['total']->toString())->toBe('0.00')
        ->and($r['expenses']['summary']['submitted']['total']->toString())->toBe('800.00')
        ->and($r['draft']['base']->toString())->toBe('5000.00')
        ->and(frReport(['expense_status' => 'pending'])['expenses']['summary']['submitted']['count'])->toBe(1)
        ->and(frReport(['expense_status' => 'approved'])['expenses']['summary']['submitted']['count'])->toBe(0);
});

// ---------- Filters, dates and opening balances ----------

it('applies unit and payment-status filters', function () {
    $b = frBaseline();
    $other = finUnit();
    finServicedBooking($other, '2000.00', ['status' => 'waiting_verification', 'completed_at' => null]);

    expect(frReport()['jobs']['totals']['bookings'])->toBe(2)
        ->and(frReport(['unit_id' => $b['unit']->id])['jobs']['totals']['collected']->toString())->toBe('15000.00')
        ->and(frReport(['unit_id' => $other->id])['jobs']['totals']['outstanding']->toString())->toBe('2000.00')
        ->and(frReport(['payment_status' => 'paid'])['jobs']['totals']['bookings'])->toBe(1)
        ->and(frReport(['payment_status' => 'outstanding'])['jobs']['rows'][0]['units'])->toBe($other->name)
        ->and(frReport(['unit_id' => $other->id])['funds']['totals']['issued']->toString())->toBe('0.00');
});

it('uses Manila business-date boundaries, lists Sunday and late collections for review, and carries unit funds forward', function () {
    $owner = frOwner();
    $unit = finUnit();
    $saturday = finLastWeek()->addDays(5)->setTime(23, 30);
    $sunday = finLastWeek()->addDays(6)->setTime(0, 30);
    finServicedBooking($unit, '1000.00', ['completion_requested_at' => $saturday, 'payment_submitted_at' => $saturday, 'completed_at' => $saturday->addMinutes(20)]);
    finServicedBooking($unit, '700.00', ['completion_requested_at' => $sunday, 'payment_submitted_at' => $sunday, 'completed_at' => $sunday->addHour()]);
    $earlier = finLastWeek()->subDays(3)->setTime(10, 0);
    finServicedBooking($unit, '900.00', ['completion_requested_at' => $earlier, 'payment_submitted_at' => $earlier, 'completed_at' => finLastWeek()->addDay()->setTime(9, 0)]);
    app(UnitFundService::class)->issue($owner, ['unit_id' => $unit->id, 'issue_kind' => 'initial', 'amount' => '500', 'entry_date' => finLastWeek()->subDays(4)->toDateString(), 'counterparty_name' => 'TL'], (string) Str::uuid());
    app(UnitFundService::class)->issue($owner, ['unit_id' => $unit->id, 'issue_kind' => 'additional', 'amount' => '300', 'entry_date' => finLastWeek()->addDay()->toDateString(), 'counterparty_name' => 'TL'], (string) Str::uuid());

    $r = frReport();
    expect($r['jobs']['totals']['collected']->toString())->toBe('1000.00')               // Saturday 23:30 is inside the week
        ->and(collect($r['review']['sunday'])->pluck('agreed')->map->toString()->all())->toBe(['700.00'])
        ->and(collect($r['review']['late'])->pluck('agreed')->map->toString()->all())->toBe(['900.00'])
        ->and($r['funds']['totals']['opening']->toString())->toBe('500.00')
        ->and($r['funds']['totals']['issued']->toString())->toBe('300.00')
        ->and($r['funds']['totals']['remaining']->toString())->toBe('800.00');

    // A custom range that includes the Sunday shows it as a normal job.
    $custom = frReport([], finLastWeek(), finLastWeek()->addDays(6));
    expect($custom['jobs']['totals']['collected']->toString())->toBe('1700.00')->and($custom['review']['sunday'])->toBe([]);
});

it('filters by historical personnel even after the unit roster and crew records change', function () {
    $b = frBaseline(personalAdvance: false);   // a personal advance would (correctly) block approval
    $svc = app(CommissionStatementService::class);
    $statement = $svc->approve($b['owner'], $svc->submitForReview($b['owner'], $svc->generate($b['owner'], finLastWeek()->toDateString(), $b['unit']->id)));
    $driverKey = "personnel:{$b['driver']->id}";

    // Later the unit gets a new driver and the crew record is corrected (after approval).
    $newDriver = finPersonnel('driver');
    $b['unit']->forceFill(['driver_name' => $newDriver->full_name, 'driver_personnel_id' => $newDriver->id])->save();
    app(JobCrewService::class)->correct($b['owner'], JobPersonnel::current()->where('personnel_id', $b['driver']->id)->first(),
        ['unit_id' => $b['unit']->id, 'participant' => "personnel:{$newDriver->id}", 'commission_role' => 'driver'], 'Driver record corrected later');

    $r = frReport(['personnel' => $driverKey]);
    expect($r['meta']['person'])->toBe($b['driver']->full_name)
        ->and($r['approved']['person_amount']->toString())->toBe('1446.00')                 // from the frozen snapshot
        ->and($r['approved']['count'])->toBe(1)
        ->and(collect($r['approved']['by_recipient'])->pluck('name')->all())->toBe([$b['driver']->full_name]);
    expect(frReport(['personnel' => "personnel:{$newDriver->id}"])['jobs']['totals']['bookings'])->toBe(1)   // current crew record
        ->and(frReport(['personnel' => "personnel:{$newDriver->id}"])['approved']['count'])->toBe(0);       // never approved for this job
    expect(app(FinanceReportService::class)->personnelOptions())->toHaveKeys([$driverKey, "personnel:{$newDriver->id}"]);
});

it('takes approved commission from frozen snapshots and counts only recorded payouts', function () {
    $b = frBaseline(personalAdvance: false);
    $svc = app(CommissionStatementService::class);
    $statement = $svc->approve($b['owner'], $svc->submitForReview($b['owner'], $svc->generate($b['owner'], finLastWeek()->toDateString(), $b['unit']->id)));
    $payouts = app(PayoutService::class);
    $kept = $payouts->record($b['owner'], $statement, "personnel:{$b['driver']->id}", 'gcash', now()->toDateString(), (string) Str::uuid(), 'GC-1');
    $voided = $payouts->record($b['owner'], $statement, "personnel:{$b['crew']->id}", 'cash', now()->toDateString(), (string) Str::uuid(), 'ACK-1');
    $payouts->void($b['owner'], $voided, 'Recorded against the wrong person');

    $r = frReport();
    expect($r['approved']['base']->toString())->toBe('12050.00')
        ->and($r['approved']['company']->toString())->toBe('7230.00')
        ->and($r['approved']['personnel']->toString())->toBe('4820.00')
        ->and($r['payouts']['count'])->toBe(1)
        ->and($r['payouts']['total']->toString())->toBe('1446.00')
        ->and(frReport(['statement_status' => 'paid'])['approved']['count'])->toBe(0)
        ->and(frReport(['statement_status' => 'approved'])['approved']['count'])->toBe(1);
});

// ---------- Exports ----------

it('exports the same totals to the printable report, PDF and Excel', function () {
    $b = frBaseline();
    [$f, $t] = frWeek();
    $q = ['from' => $f->toDateString(), 'to' => $t->toDateString()];

    $page = $this->actingAs($b['owner'])->get(route('superadmin.finance.reports.index', $q))->assertOk();
    $print = $this->actingAs($b['owner'])->get(route('superadmin.finance.reports.print', $q))->assertOk();
    foreach (['bookings">1<', 'vehicles">1<', 'collected">₱15,000.00<', 'expenses-approved">₱2,950.00<', 'expenses-personal">₱250.00<', 'funds-remaining">₱1,250.00<', 'draft-base">₱12,050.00<', 'payouts-total">₱0.00<'] as $fragment) {
        $page->assertSee('data-fr="' . $fragment, false);
        $print->assertSee('data-fr="' . $fragment, false);
    }
    $print->assertSee('FINANCE REPORT')->assertSee('Print / Save as PDF')->assertSee('Asia/Manila business dates');
    $page->assertSee(e(route('superadmin.finance.reports.xlsx', $q)), false);

    $pdf = $this->actingAs($b['owner'])->get(route('superadmin.finance.reports.pdf', $q))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

    $xlsx = $this->actingAs($b['owner'])->get(route('superadmin.finance.reports.xlsx', $q))->assertOk()->assertHeader('Content-Type', FinanceReportWorkbook::MIME);
    $path = tempnam(sys_get_temp_dir(), 'frx') . '.xlsx';
    file_put_contents($path, $xlsx->streamedContent());
    $book = IOFactory::load($path);
    @unlink($path);

    expect($book->getSheetNames())->toBe(['Summary', 'Jobs', 'For Review', 'Expenses', 'Unit Funds', 'Draft Commission', 'Approved Commission', 'Payouts']);
    $summary = collect($book->getSheetByName('Summary')->toArray(null, false, false))->filter(fn ($row) => isset($row[0], $row[1]))->mapWithKeys(fn ($row) => [$row[0] => $row[1]]);
    expect((float) $summary['Completed bookings'])->toBe(1.0)
        ->and((float) $summary['Vehicles towed'])->toBe(1.0)
        ->and((float) $summary['Verified customer collections'])->toBe(15000.0)
        ->and((float) $summary['Approved expenses'])->toBe(2950.0)
        ->and((float) $summary['Draft sharing base'])->toBe(12050.0)
        ->and((float) $summary['Remaining balance'])->toBe(1250.0)
        ->and($book->getSheetByName('Summary')->getCell('A4')->getValue())->toContain('Commission policy v1')
        ->and($book->getSheetByName('Summary')->getStyle('B10')->getNumberFormat()->getFormatCode())->toBe('"₱"#,##0.00');
});

it('labels draft amounts as pending client confirmation under an unconfirmed policy', function () {
    $owner = frOwner();
    $unit = finUnit();
    finServicedBooking($unit, '1000.00');
    [$f, $t] = frWeek();

    $this->actingAs($owner)->get(route('superadmin.finance.reports.print', ['from' => $f->toDateString(), 'to' => $t->toDateString()]))
        ->assertOk()->assertSee('Draft / Pending Client Confirmation — not approved')->assertSee('data-fr-policy="pending"', false);
    $book = app(FinanceReportWorkbook::class)->build(frReport());
    expect($book->getSheetByName('Draft Commission')->getCell('A1')->getValue())->toContain('PENDING CLIENT CONFIRMATION');
});

it('neutralises spreadsheet formulas in exported text', function () {
    $unit = finUnit();
    $unit->forceFill(['name' => '=HYPERLINK("http://evil.example","x")'])->save();
    $booking = finServicedBooking($unit, '1000.00');
    $booking->customer->forceFill(['full_name' => '+cmd|calc'])->save();

    $book = app(FinanceReportWorkbook::class)->build(frReport());
    $jobs = $book->getSheetByName('Jobs');
    $customer = $jobs->getCell('E7')->getValue();
    $units = $jobs->getCell('F7')->getValue();
    expect($customer)->toBe("'+cmd|calc")
        ->and($units)->toStartWith("'=")
        ->and($jobs->getCell('F7')->getDataType())->toBe('s')
        ->and($book->getSheetByName('Unit Funds')->getCell('A7')->getValue())->toStartWith("'=");
});

it('renders an empty period cleanly on screen and in every export', function () {
    $owner = frOwner();
    $q = ['from' => '2026-01-05', 'to' => '2026-01-10'];

    $this->actingAs($owner)->get(route('superadmin.finance.reports.index', $q))->assertOk()
        ->assertSee('data-fr-empty="jobs"', false)->assertSee('data-fr-empty="payouts"', false)->assertSee('data-fr="collected">₱0.00<', false);
    $this->actingAs($owner)->get(route('superadmin.finance.reports.pdf', $q))->assertOk();
    $this->actingAs($owner)->get(route('superadmin.finance.reports.xlsx', $q))->assertOk();
    $r = app(FinanceReportService::class)->build(CarbonImmutable::parse('2026-01-05'), CarbonImmutable::parse('2026-01-10'));
    expect($r['jobs']['rows'])->toBe([])->and($r['jobs']['totals']['vehicles'])->toBe(0)->and($r['draft']['base']->toString())->toBe('0.00');
});

it('refuses the report page and every download to non-Owners and guests', function () {
    foreach ([2, 3, 4, 5, 6] as $role) {
        $user = finUser($role);
        foreach (['index', 'print', 'pdf', 'xlsx'] as $name) {
            $this->actingAs($user)->get(route("superadmin.finance.reports.{$name}"))->assertForbidden();
        }
    }
    auth()->logout();
    foreach (['index', 'print', 'pdf', 'xlsx'] as $name) {
        $this->get(route("superadmin.finance.reports.{$name}"))->assertRedirect();
    }
});
