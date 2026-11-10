<?php

namespace App\Services\Finance\Demo;

use App\Services\Finance\FinanceException;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the Finance demonstration away from real records. Demo seeding, the demo reset and simulated payouts are
 * allowed only when ALL of these hold:
 *
 *   1. FINANCE_DEMO_ENABLED=true
 *   2. the application is not running in production
 *   3. the default database name ends with "_demo" (a separate, disposable database)
 *
 * The automated test suite may build the demo scenarios inside its own "*_testing" database (wrapped in a
 * transaction), but can never run the reset there.
 */
final class FinanceDemoGuard
{
    /** Test-only: lets the automated tests simulate another database name (for example a live one). */
    public static ?string $simulatedDatabaseName = null;

    public static function databaseName(): string
    {
        if (self::$simulatedDatabaseName !== null && app()->runningUnitTests()) {
            return self::$simulatedDatabaseName;
        }

        return (string) DB::connection()->getDatabaseName();
    }

    /** True when this installation is a demo installation (shows banners and the Demo page). */
    public static function enabled(): bool
    {
        return (bool) config('finance.demo.enabled')
            && ! app()->environment('production')
            && str_ends_with(self::databaseName(), (string) config('finance.demo.database_suffix', '_demo'));
    }

    /** Demo scenarios may be written here (demo database, or the automated test database). */
    public static function assertCanSeed(): void
    {
        if (self::enabled()) {
            return;
        }
        if (app()->runningUnitTests() && str_ends_with(self::databaseName(), '_testing')) {
            return;
        }

        throw new FinanceException(self::refusal());
    }

    /** The destructive reset (drop and rebuild) is allowed only on a demo database. */
    public static function assertCanReset(): void
    {
        // The REAL connection must be a demo database too (never the simulated name used by tests).
        $real = (string) DB::connection()->getDatabaseName();
        if (! self::enabled() || ! str_ends_with($real, (string) config('finance.demo.database_suffix', '_demo'))) {
            throw new FinanceException(self::refusal());
        }
    }

    private static function refusal(): string
    {
        return 'Refused: demo data can only be used when FINANCE_DEMO_ENABLED=true, the app is not in production, and the database name ends with "'
            . config('finance.demo.database_suffix', '_demo') . '" (current database: "' . self::databaseName() . '", environment: "' . app()->environment() . '").';
    }
}
