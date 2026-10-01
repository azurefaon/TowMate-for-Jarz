<?php

namespace App\Console\Commands;

use App\Services\PersonnelReconciliationService;
use Illuminate\Console\Command;

class ReconcilePersonnelLinks extends Command
{
    protected $signature = 'personnel:reconcile {--apply : Write the safe links instead of only reporting}';

    protected $description = 'Report (and optionally apply) provably safe links between legacy crew names and Personnel records';

    public function handle(PersonnelReconciliationService $reconciliation): int
    {
        $plan = $reconciliation->plan();

        $this->table(
            ['Status', 'Scope', 'Where', 'Legacy name', 'Role', 'Personnel ID'],
            $plan->map(fn (array $entry) => [
                $entry['status'], $entry['scope'], $entry['label'], $entry['name'], $entry['role'], $entry['personnel_id'] ?? '-',
            ])->all()
        );

        $this->info(json_encode($reconciliation->summarize($plan)));

        if ($this->option('apply')) {
            $this->info($reconciliation->apply() . ' link(s) written. Unmatched, inactive and ambiguous entries were left untouched.');
        } else {
            $this->line('Dry run. Re-run with --apply to write the linked entries.');
        }

        return self::SUCCESS;
    }
}
