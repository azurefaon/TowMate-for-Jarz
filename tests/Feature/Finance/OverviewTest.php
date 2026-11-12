<?php

use App\Models\Finance\CommissionPolicy;
use App\Models\User;
use App\Services\Finance\CommissionStatementService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceOverviewService;
use App\Services\Finance\PayoutService;
use App\Services\Finance\UnitFundService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__ . '/finance_helpers.php';

// Phase 8: Owner Finance Overview. Figures come from the owning services and stay separated by kind.

beforeEach(function () {
    Storage::fake('local');
});

function ovOwner(): User
{
    return User::where('role_id', 1)->first() ?? finOwner();
}

function ovExpense($booking, $unit, string $amount, bool $approve = true)
{
    $svc = app(ExpenseService::class);
    $e = $svc->submit(ovOwner(), ['unit_id' => $unit->id, 'expense_scope' => 'job', 'booking_id' => $booking->booking_code,
        'expense_date' => finLastWeek()->addDays(2)->toDateString(), 'category' => 'fuel_diesel', 'description' => 'Diesel', 'amount' => $amount,
        'fundings' => ['unit_fund' => $amount]], (string) Str::uuid(), [UploadedFile::fake()->image('r.jpg', random_int(10, 90), random_int(10, 90))]);

    return $approve ? $svc->approve(ovOwner(), $e) : $e;
}

function ovBuild(?int $unitId = null): array
{
    return app(FinanceOverviewService::class)->build(finLastWeek(), finLastWeek()->addDays(5), $unitId);
}

/** Client baseline with unit funds: 15,000 collected, 3,000 issued, 2,950 spent, 50 returned. */
function ovBaseline(bool $confirmed = true): array
{
    $owner = ovOwner();
    if ($confirmed && ! CommissionPolicy::where('status', 'active')->exists()) {
        finActivePolicy($owner);
    }
    $leader = finUser(3);
    $unit = finUnit($leader);
    $booking = finServicedBooking($unit, '15000.00');
    $people = finRecordCrew($owner, $booking, $unit, $leader);
    $funds = app(UnitFundService::class);
    $day = finLastWeek()->addDays(2)->toDateString();
    $funds->issue($owner, ['unit_id' => $unit->id, 'issue_kind' => 'initial', 'amount' => '3000', 'entry_date' => finLastWeek()->toDateString(), 'counterparty_name' => 'Tomas'], (string) Str::uuid());
    ovExpense($booking, $unit, '1700.00');
    ovExpense($booking, $unit, '1250.00');
    $funds->recordReturn($owner, ['unit_id' => $unit->id, 'amount' => '50', 'entry_date' => $day, 'counterparty_name' => 'Tomas'], (string) Str::uuid());

    return ['owner' => $owner, 'unit' => $unit, 'booking' => $booking] + $people;
}

it('shows actual collections, expenses, unit funds and the draft commission from their services', function () {
    $b = ovBaseline();
    $o = ovBuild();

    expect($o['collections']['collected']->toString())->toBe('15000.00')
        ->and($o['collections']['outstanding']->toString())->toBe('0.00')
        ->and($o['expenses']['approved']['total']->toString())->toBe('2950.00')
        ->and($o['funds']['issued']->toString())->toBe('3000.00')
        ->and($o['funds']['spent']->toString())->toBe('2950.00')
        ->and($o['funds']['returned']->toString())->toBe('50.00')
        ->and($o['funds']['remaining']->toString())->toBe('0.00')
        ->and($o['draft']['base']->toString())->toBe('12050.00')
        ->and($o['draft']['company']->toString())->toBe('7230.00')
        ->and($o['draft']['crew']->toString())->toBe('4820.00')
        ->and($o['draft']['unallocated']->toString())->toBe('0.00')
        ->and($o['draft']['by_role']['driver']['allocated']->toString())->toBe('1446.00')
        ->and($o['approved']['count'])->toBe(0)
        ->and($o['approved']['payouts_recorded']->toString())->toBe('0.00');
});

it('keeps approved commission and recorded payouts separate from the draft estimate', function () {
    $b = ovBaseline();
    $svc = app(CommissionStatementService::class);
    $statement = $svc->approve($b['owner'], $svc->submitForReview($b['owner'], $svc->generate($b['owner'], finLastWeek()->toDateString(), $b['unit']->id)));
    app(PayoutService::class)->record($b['owner'], $statement, "personnel:{$b['driver']->id}", 'cash', now()->toDateString(), (string) Str::uuid(), 'ACK-1');

    $o = ovBuild();
    expect($o['approved']['count'])->toBe(1)
        ->and($o['approved']['base']->toString())->toBe('12050.00')
        ->and($o['approved']['company']->toString())->toBe('7230.00')
        ->and($o['approved']['personnel']->toString())->toBe('4820.00')
        ->and($o['approved']['payouts_recorded']->toString())->toBe('1446.00')
        ->and($o['approved']['payouts_remaining']->toString())->toBe('3374.00')
        ->and($o['draft']['base']->toString())->toBe('12050.00');   // still shown as an estimate, never summed with approved

    $this->actingAs($b['owner'])->get(route('superadmin.finance.overview', ['from' => finLastWeek()->toDateString(), 'to' => finLastWeek()->addDays(5)->toDateString()]))
        ->assertOk()
        ->assertSee('Customer collections (actual)')
        ->assertSee('Commission — draft estimate')
        ->assertSee('Approved commission and recorded payouts')
        ->assertSee('data-ov="collected">₱15,000.00<', false)
        ->assertSee('data-ov="draft-base">₱12,050.00<', false)
        ->assertSee('data-ov="approved-personnel">₱4,820.00<', false)
        ->assertSee('data-ov="payouts-recorded">₱1,446.00<', false)
        ->assertSee('data-ov="funds-remaining">₱0.00<', false);
});

it('filters by unit and lists pending reviews and approval blockers', function () {
    $b = ovBaseline(confirmed: false);
    $other = finUnit();
    $otherJob = finServicedBooking($other, '2000.00');
    ovExpense($otherJob, $other, '300.00', approve: false);

    $all = ovBuild();
    $one = ovBuild($b['unit']->id);
    $two = ovBuild($other->id);

    expect($all['collections']['collected']->toString())->toBe('17000.00')
        ->and($one['collections']['collected']->toString())->toBe('15000.00')
        ->and($two['collections']['collected']->toString())->toBe('2000.00')
        ->and($two['expenses']['submitted']['count'])->toBe(1)
        ->and($one['expenses']['submitted']['count'])->toBe(0)
        ->and(array_keys($all['draft']['blockers']))->toContain('policy_for_client_review', 'no_personnel', 'expenses_pending');

    $this->actingAs($b['owner'])->get(route('superadmin.finance.overview', ['from' => finLastWeek()->toDateString(), 'to' => finLastWeek()->addDays(5)->toDateString(), 'unit_id' => $other->id]))
        ->assertOk()
        ->assertSee('data-ov="collected">₱2,000.00<', false)
        ->assertSee('data-ov-blocker="no_personnel"', false)
        ->assertSee('1 expense(s) awaiting Dispatcher verification');
});

it('lets only the Owner open the Overview', function () {
    foreach ([2, 3, 4, 5, 6] as $role) {
        $this->actingAs(finUser($role))->get(route('superadmin.finance.overview'))->assertForbidden();
    }
    auth()->logout();
    $this->get(route('superadmin.finance.overview'))->assertRedirect();
});

it('links every Finance page from the sidebar and renders each one for the Owner', function () {
    $owner = finOwner();
    $page = $this->actingAs($owner)->get(route('superadmin.finance.overview'))->assertOk();
    // The sidebar links each module once; Driver Records and Statements & Payouts are tabs inside their module.
    $routes = ['overview', 'collections.index', 'unit-funds.index', 'expenses.index', 'crew.index', 'commission.index', 'reports.index', 'client-review.index'];
    foreach ($routes as $name) {
        $page->assertSee('href="' . route("superadmin.finance.{$name}") . '"', false);
    }
    foreach ([...$routes, 'driver-records.index', 'statements.index'] as $name) {
        $this->actingAs($owner)->get(route("superadmin.finance.{$name}"))->assertOk()->assertDontSee('Lorem')->assertDontSee('TODO');
    }
});
