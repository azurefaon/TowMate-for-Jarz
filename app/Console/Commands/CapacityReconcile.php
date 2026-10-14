<?php

namespace App\Console\Commands;

use App\Exceptions\Booking\CapacityReconciliationRefused;
use App\Services\CapacityReconciler;
use App\Services\CapacityReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings scheduled-booking capacity to its normalized state: every accepted,
 * not-ended scheduled booking (completed ones included) carries its CURRENT
 * scheduled_date as capacity_reserved_date, and every date's counter equals
 * the number of such bookings. See CapacityReconciliationService.
 *
 * DRY RUN BY DEFAULT: without --apply it only reads (inside a READ ONLY
 * transaction) and prints the plan with a fingerprint.
 *
 * --apply changes data and needs ALL of:
 *   --confirm-plan=<fingerprint>  copied from the dry run; the apply refuses if the data no longer matches it
 *   --allow-production            when APP_ENV=production
 *   typing the database name      when run interactively
 * and it refuses unless booking writes are proven quiesced (table locks; see CapacityReconciler).
 *
 * No customer details are printed or logged: booking codes and dates only.
 */
class CapacityReconcile extends Command
{
    protected $signature = 'towmate:capacity-reconcile
        {--apply : Change data. Without it this is a dry run that changes nothing}
        {--confirm-plan= : The plan fingerprint printed by the dry run (required with --apply)}
        {--allow-production : Required with --apply when APP_ENV=production}
        {--lock-timeout=5000 : Milliseconds to wait for the table locks that prove booking writes are quiesced}
        {--json : Print the result as JSON}';

    protected $description = 'Normalize scheduled-booking capacity markers and counters. DRY RUN unless --apply is given.';

    public function handle(CapacityReconciliationService $reconciliation, CapacityReconciler $reconciler): int
    {
        $plan = $reconciliation->report();   // read-only transaction

        if (! $this->option('apply')) {
            return $this->dryRun($plan);
        }

        return $this->apply($plan, $reconciler);
    }

    protected function dryRun(array $plan): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($this->machineReadable($plan, 'dry-run'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $plan['blockers'] ? self::FAILURE : self::SUCCESS;
        }

        $this->info('DRY RUN: nothing was changed.');
        $this->line('Database: ' . $this->databaseLabel());
        $this->newLine();

        $this->line('Desired state: each accepted, not-ended scheduled booking (completed included) is marked with its CURRENT scheduled date, and each date\'s counter equals the number of such bookings.');
        $s = $plan['summary'];
        $this->table(['Planned change', 'Count'], [
            ['Markers to set (accepted, unmarked)', $s['markers_to_set']],
            ['Markers to move to the current date', $s['markers_to_move']],
            ['Markers to clear (ended or never accepted)', $s['markers_to_clear']],
            ['Counters to correct', $s['counters_to_change']],
        ]);

        $this->listChanges('Set marker', collect($plan['changes']['set'])->map(fn ($c) => "{$c['code']} -> {$c['to']}"));
        $this->listChanges('Move marker', collect($plan['changes']['move'])->map(fn ($c) => "{$c['code']}: {$c['from']} -> {$c['to']}"));
        $this->listChanges('Clear marker', collect($plan['changes']['clear'])->map(fn ($c) => "{$c['code']} ({$c['reason']}, was {$c['from']})"));
        $this->listChanges('Correct counter', collect($plan['changes']['counters'])->map(fn ($c, $date) => "{$date}: " . ($c['from'] ?? '(no row)') . " -> {$c['to']}"));

        $this->newLine();
        $this->line('Historical observations (explain the drift; never change the target): '
            . "rescheduled legacy bookings={$s['legacy_rescheduled']}, completed bookings keeping their slot={$s['completed_consumed']}, accepted bookings that later ended={$plan['observations']['accepted_then_ended']}.");

        foreach (['group_mixed_acceptance' => 'Groups with an accepted vehicle and a still-pending one', 'group_split_dates' => 'Groups whose accepted vehicles are on different dates (informational)'] as $key => $label) {
            if ($plan['anomalies'][$key]) {
                $this->warn($label . ': ' . collect($plan['anomalies'][$key])->pluck('group_code')->implode(', '));
            }
        }

        if ($plan['blockers']) {
            $this->newLine();
            $this->error('This cannot be applied until these are resolved:');
            foreach ($plan['blockers'] as $blocker) {
                $this->line('  - ' . $blocker);
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Plan fingerprint: ' . $plan['fingerprint']);
        if (! array_filter($plan['changes'])) {
            $this->info('Already normalized: an apply would change nothing.');
        } else {
            $this->line('To apply exactly this plan: php artisan towmate:capacity-reconcile --apply --confirm-plan=' . $plan['fingerprint'] . (app()->isProduction() ? ' --allow-production' : ''));
        }

        return self::SUCCESS;
    }

    protected function apply(array $plan, CapacityReconciler $reconciler): int
    {
        if (app()->isProduction() && ! $this->option('allow-production')) {
            return $this->refuse('APP_ENV is production: pass --allow-production as well to apply to a production database.');
        }

        $confirmed = (string) $this->option('confirm-plan');
        if ($confirmed === '') {
            return $this->refuse('--apply needs --confirm-plan=<fingerprint>. Run the dry run first and use the fingerprint it prints.');
        }

        if ($this->input->isInteractive()) {
            $database = (string) DB::connection()->getDatabaseName();
            $this->warn('This changes booking and capacity data in database "' . $database . '" (' . $this->databaseLabel() . ').');
            if ($this->ask('Type the database name to continue') !== $database) {
                return $this->refuse('The name did not match. Nothing was changed.');
            }
        }

        try {
            $result = $reconciler->apply($confirmed, (int) $this->option('lock-timeout'));
        } catch (CapacityReconciliationRefused $e) {
            return $this->refuse($e->getMessage());
        }

        if ($this->option('json')) {
            $this->line(json_encode(['mode' => 'apply', 'applied' => $result], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info($result['changed']
            ? "Applied: {$result['set']} marker(s) set, {$result['moved']} moved, {$result['cleared']} cleared, {$result['counters']} counter(s) corrected. Verified before commit."
            : 'Nothing to change: the data was already normalized.');

        return self::SUCCESS;
    }

    protected function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    protected function machineReadable(array $plan, string $mode): array
    {
        return [
            'mode' => $mode,
            'database' => $this->databaseLabel(),
            'fingerprint' => $plan['fingerprint'],
            'summary' => $plan['summary'],
            'blockers' => $plan['blockers'],
            'changes' => $plan['changes'],
            'observations' => $plan['observations'],
            'anomalies' => $plan['anomalies'],
        ];
    }

    protected function databaseLabel(): string
    {
        $config = DB::connection()->getConfig();

        return ($config['database'] ?? '?') . ' @ ' . ($config['host'] ?? '?') . ':' . ($config['port'] ?? '?') . ' [' . app()->environment() . ']';
    }

    protected function listChanges(string $label, $lines, int $limit = 25): void
    {
        $lines = $lines->values();

        if ($lines->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line("{$label} ({$lines->count()}):");
        foreach ($lines->take($limit) as $line) {
            $this->line('  ' . $line);
        }
        if ($lines->count() > $limit) {
            $this->line('  ... and ' . ($lines->count() - $limit) . ' more');
        }
    }
}
