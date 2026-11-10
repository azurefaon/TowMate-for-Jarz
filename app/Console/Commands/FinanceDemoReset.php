<?php

namespace App\Console\Commands;

use App\Services\Finance\Demo\FinanceDemoGuard;
use App\Services\Finance\Demo\FinanceDemoScenarios;
use App\Services\Finance\FinanceException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Rebuilds the Owner Finance demonstration database from scratch: drops and re-runs every migration on the DEMO
 * database, then builds the six demo scenarios through the real Finance services.
 *
 * Refuses unless FINANCE_DEMO_ENABLED=true, APP_ENV is not production, and the database name ends with "_demo".
 * Typical use:  php artisan finance:demo-reset --env=demo
 */
class FinanceDemoReset extends Command
{
    protected $signature = 'finance:demo-reset {--force : Do not ask for confirmation}';

    protected $description = 'Rebuild the isolated Owner Finance demonstration database (demo databases only).';

    public function handle(FinanceDemoScenarios $scenarios): int
    {
        try {
            FinanceDemoGuard::assertCanReset();
        } catch (FinanceException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $database = FinanceDemoGuard::databaseName();
        if (! $this->option('force') && ! $this->confirm("This drops and rebuilds every table in the demo database \"{$database}\". Continue?")) {
            return self::FAILURE;
        }

        $this->info("Rebuilding demo database \"{$database}\"…");
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->line(trim(Artisan::output()));

        $result = $scenarios->seed();

        $this->newLine();
        $this->info('Demo ready. Sign in as the Owner:');
        $this->line("  Email:    {$result['owner_email']}");
        $this->line("  Password: {$result['password']}" . (config('finance.demo.password') ? '' : '  (generated; set FINANCE_DEMO_PASSWORD to fix it)'));
        $this->line("  Week:     {$result['week_start']} (Monday–Saturday)");
        foreach ($result['scenarios'] as $key => $s) {
            $this->line('  - ' . str_replace('_', ' ', $key) . ': booking ' . $s['booking'] . (isset($s['statement']) ? ", statement {$s['statement']}" : ''));
        }

        return self::SUCCESS;
    }
}
