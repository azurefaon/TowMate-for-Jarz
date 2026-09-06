<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Only this exact database may ever be touched by RefreshDatabase.
     */
    private const ALLOWED_TEST_DATABASE = 'towmate_jarz_testing';

    /**
     * parent::refreshApplication() is what actually boots the app and loads
     * .env.testing (see Illuminate\Foundation\Testing\Concerns\InteractsWithTestCaseLifecycle
     * ::setUpTheTestEnvironment(), which calls refreshApplication() and only
     * afterwards calls setUpTraits() — the method that invokes RefreshDatabase).
     * Running the guard here, immediately after the parent call, is therefore
     * the earliest point at which the real resolved config/connection exists,
     * and it is still strictly before RefreshDatabase ever gets control.
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $this->guardAgainstUnsafeTestDatabase();
    }

    /**
     * PostgreSQL against a database named EXACTLY towmate_jarz_testing is the
     * only combination allowed to run automated tests in this project.
     *
     * Deliberately validates the ACTUAL resolved connection (DB::connection())
     * rather than raw env vars or the raw config array: config/database.php's
     * pgsql block sets 'url' => env('DATABASE_URL'), and
     * Illuminate\Support\ConfigurationUrlParser::parseConfiguration() merges a
     * present DATABASE_URL's host/database/user/pass OVER the discrete DB_*
     * config values at connection-build time — a merge that never gets written
     * back into config(). So config('database.connections.pgsql.database')
     * alone could look safe while the real connection silently points
     * elsewhere. DB::connection()->getDatabaseName() reflects what was
     * actually built, after any such override.
     */
    private function guardAgainstUnsafeTestDatabase(): void
    {
        if (app()->environment() !== 'testing') {
            throw new RuntimeException(
                "Refusing to run tests: environment is '" . app()->environment() . "', expected 'testing'."
            );
        }

        if (! blank(env('DATABASE_URL'))) {
            throw new RuntimeException(
                'Refusing to run tests: DATABASE_URL is set. This is the Railway/production '
                . 'connection convention, can override DB_* variables at connection time, '
                . 'and must never be present during tests.'
            );
        }

        if (config('database.default') !== 'pgsql') {
            throw new RuntimeException(
                "Refusing to run tests: default connection is '" . config('database.default') . "', expected 'pgsql'. "
                . 'SQLite (including :memory:) is not a supported test database for this project.'
            );
        }

        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql' || $connection->getDatabaseName() !== self::ALLOWED_TEST_DATABASE) {
            throw new RuntimeException(
                "Refusing to run tests: resolved connection is '{$connection->getDriverName()}:{$connection->getDatabaseName()}', "
                . 'expected exactly \'pgsql:' . self::ALLOWED_TEST_DATABASE . "'. Refusing to risk running RefreshDatabase "
                . 'against the real development database, Railway, or any other database.'
            );
        }
    }
}
