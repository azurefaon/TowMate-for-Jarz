<?php

namespace App\Services\OnlinePayments;

use App\Models\User;

/**
 * Every "may the sandbox run at all" decision lives here: feature flag, environment,
 * who may use it, and whether the credentials are test-mode ones. A live key is refused
 * outright, so this integration can never take real money.
 */
class SandboxGuard
{
    /**
     * Production-like environments. Listing them in PAYMONGO_SANDBOX_ENVIRONMENTS is never enough: they need the
     * separate PAYMONGO_SANDBOX_ALLOW_PRODUCTION opt-in (capstone demo). Even then only test keys work.
     */
    public const PRODUCTION_ENVIRONMENTS = ['production', 'prod'];

    public function enabled(): bool
    {
        return (bool) config('paymongo.enabled') && $this->environmentAllowed();
    }

    public function isProduction(): bool
    {
        return in_array(strtolower(trim((string) app()->environment())), self::PRODUCTION_ENVIRONMENTS, true);
    }

    /** Production only with the explicit opt-in; any other environment must be listed. */
    public function environmentAllowed(): bool
    {
        if ($this->isProduction()) {
            return config('paymongo.allow_production') === true;
        }

        return in_array((string) app()->environment(), (array) config('paymongo.allowed_environments'), true);
    }

    /**
     * Who may pay online: any active Customer account, however it signs in (email/password or Google). Staff and
     * disabled accounts never can. Money stays TEST-only through enabled() and credentialsProblem(); each booking paid
     * this way is marked is_demo, so it stays out of real revenue.
     */
    public function customerEligible(?User $user): bool
    {
        return $user !== null && $user->canUseApi() && $user->role?->name === 'Customer';
    }

    /** A dedicated demo account: EVERY booking it makes, Cash included, is marked is_demo (see Booking::creating). */
    public function userAllowed(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $emails = (array) config('paymongo.test_user_emails');
        $ids = (array) config('paymongo.test_user_ids');

        return in_array((int) $user->id, $ids, true)
            || in_array(strtolower((string) $user->email), $emails, true);
    }

    /** null when usable, otherwise a short reason (never contains the key). */
    public function credentialsProblem(): ?string
    {
        $key = (string) config('paymongo.secret_key');

        if ($key === '') {
            return 'missing_secret_key';
        }

        // Only an explicit test key is acceptable; sk_live_ and anything unrecognised are refused.
        if (! str_starts_with($key, 'sk_test_')) {
            return 'live_or_unknown_key_rejected';
        }

        return null;
    }

    /** Name of the database the default connection points at (read from config; no connection is opened). */
    public function databaseName(): string
    {
        $connection = (string) config('database.default');

        return (string) config("database.connections.{$connection}.database");
    }

    /**
     * Pilot tooling that writes data or contacts PayMongo only runs against a database whose name says
     * it is a throw-away one (…_testing, …_pilot, …_sandbox, …_isolated). The shared development
     * database and production never match.
     */
    public function databaseIsolated(): bool
    {
        return (bool) preg_match('/_(testing|pilot|sandbox|isolated)$/', $this->databaseName());
    }

    public function webhookSecretProblem(): ?string
    {
        return trim((string) config('paymongo.webhook_secret')) === '' ? 'missing_webhook_secret' : null;
    }

    public function secretKey(): string
    {
        // Defence in depth: even a code path that skipped enabled() cannot reach PayMongo from production.
        if (! $this->environmentAllowed()) {
            throw new GatewayException('Online payment is not available in this environment.');
        }

        if ($problem = $this->credentialsProblem()) {
            throw new GatewayException('Online payment is not configured (' . $problem . ').');
        }

        return (string) config('paymongo.secret_key');
    }
}
