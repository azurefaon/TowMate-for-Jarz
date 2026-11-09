<?php

use App\Models\Finance\CommissionPolicy;
use App\Models\Finance\Expense;
use App\Services\Finance\CommissionPolicyService;
use App\Services\Finance\CommissionStatementService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\PayoutService;
use App\Services\Finance\UnitFundService;
use App\Support\Finance\FinanceAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

require_once __DIR__ . '/finance_helpers.php';

$nonOwnerRoles = ['Dispatcher' => 2, 'Team Leader' => 3, 'Driver' => 4, 'Customer' => 5, 'System Admin' => 6];

it('grants every Owner-only finance ability to an active Owner', function () {
    $owner = finOwner();

    foreach (FinanceAccess::OWNER_ONLY as $ability) {
        expect(Gate::forUser($owner)->allows($ability))->toBeTrue($ability);
    }
});

it('denies every Owner-only finance ability to other roles', function (int $roleId) {
    $user = finUser($roleId);

    foreach (FinanceAccess::OWNER_ONLY as $ability) {
        expect(Gate::forUser($user)->allows($ability))->toBeFalse("role {$roleId} must not have {$ability}");
    }
})->with($nonOwnerRoles);

it('denies an inactive or archived Owner', function (array $state) {
    $owner = finUser(1, $state);

    foreach (FinanceAccess::OWNER_ONLY as $ability) {
        expect(Gate::forUser($owner)->allows($ability))->toBeFalse();
    }
})->with([
    'inactive' => [['status' => 'inactive']],
    'archived' => [['archived_at' => now()]],
]);

it('limits expense submission to Team Leaders and the Owner, and verification to Dispatchers', function () {
    expect(Gate::forUser(finUser(3))->allows(FinanceAccess::SUBMIT_EXPENSE))->toBeTrue()
        ->and(Gate::forUser(finOwner())->allows(FinanceAccess::SUBMIT_EXPENSE))->toBeTrue()
        ->and(Gate::forUser(finUser(2))->allows(FinanceAccess::SUBMIT_EXPENSE))->toBeFalse()
        ->and(Gate::forUser(finUser(4))->allows(FinanceAccess::SUBMIT_EXPENSE))->toBeFalse()
        ->and(Gate::forUser(finUser(2))->allows(FinanceAccess::VERIFY_EXPENSE))->toBeTrue()
        ->and(Gate::forUser(finUser(3))->allows(FinanceAccess::VERIFY_EXPENSE))->toBeFalse()
        ->and(Gate::forUser(finOwner())->allows(FinanceAccess::VERIFY_EXPENSE))->toBeFalse();
});

it('refuses Owner-only service actions for every other role', function (int $roleId) {
    $actor = finUser($roleId);
    $owner = finOwner();
    $unit = finUnit();

    $attempts = [
        'issue funds' => fn () => app(UnitFundService::class)->issue($actor, ['unit_id' => $unit->id, 'amount' => '100', 'issue_kind' => 'initial', 'entry_date' => now()->toDateString()], (string) Str::uuid()),
        'record returned funds' => fn () => app(UnitFundService::class)->recordReturn($actor, ['unit_id' => $unit->id, 'amount' => '100', 'entry_date' => now()->toDateString()], (string) Str::uuid()),
        'draft policy' => fn () => app(CommissionPolicyService::class)->createDraft($actor, 'X', ['company' => '60', 'driver' => '12', 'team_leader' => '10', 'rider' => '10', 'crew_mate' => '8']),
        'activate policy' => fn () => app(CommissionPolicyService::class)->activate($actor, CommissionPolicy::sole(), now()->toDateString()),
        'prepare statement' => fn () => app(CommissionStatementService::class)->prepareDraft($actor, finMonday(), CommissionPolicy::sole()),
    ];

    foreach ($attempts as $label => $attempt) {
        expect($attempt)->toThrow(AuthorizationException::class, null, $label);
    }

    // Approval and payout recording, against real records.
    $expense = app(ExpenseService::class)->submit($owner, ['unit_id' => $unit->id, 'amount' => '50', 'expense_date' => now()->toDateString(), 'category' => 'fuel_diesel', 'funding_source' => 'company_direct', 'description' => 'Office supplies'], (string) Str::uuid());
    expect(fn () => app(ExpenseService::class)->approve($actor, $expense))->toThrow(AuthorizationException::class);

    $policy = finActivePolicy($owner);
    ['statement' => $statement, 'driver' => $driver] = finDraftStatement($policy);
    expect(fn () => app(CommissionStatementService::class)->approve($actor, $statement))->toThrow(AuthorizationException::class);

    app(CommissionStatementService::class)->approve($owner, $statement);
    expect(fn () => app(PayoutService::class)->record($actor, $statement->fresh(), "personnel:{$driver->id}", 'cash', now()->toDateTimeString(), (string) Str::uuid()))
        ->toThrow(AuthorizationException::class);

    expect(Expense::find($expense->id)->status)->toBe('submitted')
        ->and($statement->fresh()->payouts()->count())->toBe(0);
})->with($nonOwnerRoles);
