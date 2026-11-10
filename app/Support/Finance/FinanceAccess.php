<?php

namespace App\Support\Finance;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Backend authorization for the Owner Finance module.
 *
 * Financial management, approvals, commission policy and payout recording are Owner-only (role_id 1).
 * The confirmed expense flow adds two narrower abilities: a Team Leader submits an expense for their
 * unit, and a Dispatcher verifies it before the Owner approves. Archived or inactive accounts never pass.
 */
final class FinanceAccess
{
    public const ROLE_OWNER = 1;
    public const ROLE_DISPATCHER = 2;
    public const ROLE_TEAM_LEADER = 3;

    public const VIEW = 'finance.view';
    public const MANAGE_FUNDS = 'finance.funds.manage';
    public const SUBMIT_EXPENSE = 'finance.expenses.submit';
    public const VERIFY_EXPENSE = 'finance.expenses.verify';
    public const APPROVE_EXPENSE = 'finance.expenses.approve';
    public const MANAGE_POLICIES = 'finance.policies.manage';
    public const PREPARE_STATEMENTS = 'finance.statements.prepare';
    public const APPROVE_STATEMENTS = 'finance.statements.approve';
    public const RECORD_PAYOUTS = 'finance.payouts.record';
    public const ALLOCATE_REVENUE = 'finance.revenue.allocate';
    public const MANAGE_DEDUCTIONS = 'finance.deductions.manage';

    /** Abilities that belong to the Owner alone. */
    public const OWNER_ONLY = [
        self::VIEW,
        self::MANAGE_FUNDS,
        self::APPROVE_EXPENSE,
        self::MANAGE_POLICIES,
        self::PREPARE_STATEMENTS,
        self::APPROVE_STATEMENTS,
        self::RECORD_PAYOUTS,
        self::ALLOCATE_REVENUE,
        self::MANAGE_DEDUCTIONS,
    ];

    public static function register(): void
    {
        foreach (self::OWNER_ONLY as $ability) {
            Gate::define($ability, fn (User $user) => self::isOwner($user));
        }

        Gate::define(self::SUBMIT_EXPENSE, fn (User $user) => self::isActive($user)
            && in_array((int) $user->role_id, [self::ROLE_OWNER, self::ROLE_TEAM_LEADER], true));

        Gate::define(self::VERIFY_EXPENSE, fn (User $user) => self::isActive($user)
            && (int) $user->role_id === self::ROLE_DISPATCHER);
    }

    public static function isOwner(?User $user): bool
    {
        return $user !== null && self::isActive($user) && (int) $user->role_id === self::ROLE_OWNER;
    }

    public static function isActive(User $user): bool
    {
        return $user->archived_at === null && $user->status !== 'inactive';
    }
}
