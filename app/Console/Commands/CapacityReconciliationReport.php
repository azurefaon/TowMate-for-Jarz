<?php

namespace App\Console\Commands;

use App\Services\CapacityReconciliationService;
use Illuminate\Console\Command;

/**
 * READ-ONLY. Compares stored scheduled-booking capacity with the desired
 * (normalized) state and lists what is inconsistent. It changes nothing; the
 * correction is `towmate:capacity-reconcile` (dry run by default).
 */
class CapacityReconciliationReport extends Command
{
    protected $signature = 'towmate:capacity-report
        {--only-differences : Hide dates whose stored count already matches}
        {--json : Print the report as JSON}';

    protected $description = 'READ-ONLY: compare booking_capacity with the normalized accounting state and list inconsistencies.';

    public function handle(CapacityReconciliationService $reconciliation): int
    {
        $report = $reconciliation->report();
        $dates = collect($report['dates'])
            ->when($this->option('only-differences'), fn ($rows) => $rows->where('status', '!=', 'ok'))
            ->values();

        if ($this->option('json')) {
            $this->line(json_encode([
                'summary' => $report['summary'],
                'dates' => $dates->all(),
                'legacy' => $report['legacy'],
                'anomalies' => $report['anomalies'],
                'observations' => $report['observations'],
                'blockers' => $report['blockers'],
                'fingerprint' => $report['fingerprint'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Capacity report (read-only; nothing was changed).');
        $this->line('DESIRED STATE: every accepted, not-ended scheduled booking (completed included) holds one slot on its CURRENT scheduled date; each date\'s counter equals that number.');
        $this->newLine();

        if ($dates->isEmpty()) {
            $this->line('No dates to show.');
        } else {
            $this->table(
                ['Date', 'Stored', 'Expected', 'Diff', 'Status', 'Marked', 'Unmarked', 'Completed', 'Accepted then ended'],
                $dates->map(fn ($row) => [
                    $row['date'],
                    $row['stored'] ?? '(no row)',
                    $row['expected'],
                    sprintf('%+d', $row['difference']),
                    $row['status'],
                    $row['marked_holders'],
                    $row['legacy_holders'],
                    $row['completed_holders'],
                    $row['accepted_then_ended'],
                ])->all(),
            );
        }

        $legacy = collect($report['legacy']);
        if ($legacy->isNotEmpty()) {
            $this->newLine();
            $this->warn('Eligible bookings without a marker (' . $legacy->count() . '):');
            $this->table(
                ['Booking', 'Status', 'Scheduled date', 'Rescheduled before (history)', 'Marker it will get'],
                $legacy->map(fn ($row) => [$row['booking_code'], $row['status'], $row['scheduled_date'], $row['rescheduled'] ? 'yes' : 'no', $row['suggested_marker']])->all(),
            );
        }

        $labels = [
            'eligible_without_date' => 'Accepted bookings with no scheduled date (BLOCK the apply; a date is never guessed)',
            'ended_with_marker' => 'Cancelled/rejected bookings still carrying a marker (marker will be cleared)',
            'marker_without_acceptance' => 'Markers on bookings that were never accepted (marker will be cleared)',
            'marker_differs_from_date' => 'Markers that differ from the current scheduled date (will be moved)',
            'group_mixed_acceptance' => 'Groups with an accepted vehicle and a still-pending one (review)',
            'group_split_dates' => 'Groups whose accepted vehicles are on different dates (informational)',
        ];
        foreach ($labels as $key => $label) {
            if (! empty($report['anomalies'][$key])) {
                $this->newLine();
                $this->warn($label . ': ' . count($report['anomalies'][$key]));
                $this->line(collect($report['anomalies'][$key])->map(fn ($row) => implode(' ', array_map(fn ($v) => is_array($v) ? implode(',', $v) : (string) $v, $row)))->implode(PHP_EOL));
            }
        }

        $this->newLine();
        $this->line('HISTORICAL OBSERVATIONS (explain the drift; they never change the target): '
            . 'rescheduled legacy bookings=' . count($report['observations']['rescheduled_legacy'])
            . ', completed bookings keeping their slot=' . $report['observations']['completed_consumed']
            . ', accepted bookings that later ended=' . $report['observations']['accepted_then_ended'] . '.');

        $this->newLine();
        $this->line('Summary: ' . collect($report['summary'])->map(fn ($value, $key) => "{$key}={$value}")->implode('  '));
        $this->line('To correct: php artisan towmate:capacity-reconcile   (dry run first; it changes nothing without --apply)');

        return self::SUCCESS;
    }
}
