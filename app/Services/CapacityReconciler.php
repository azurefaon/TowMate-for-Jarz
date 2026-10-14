<?php

namespace App\Services;

use App\Exceptions\Booking\CapacityReconciliationRefused;
use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies the normalized capacity accounting computed by
 * CapacityReconciliationService::plan(), in a single transaction.
 *
 * Safety, in the order it happens:
 *  1. QUIESCENCE. Before reading anything it takes table-level EXCLUSIVE locks
 *     on bookings and booking_capacity (bookings first, then capacity: the
 *     application's own order), with a short lock_timeout. EXCLUSIVE conflicts
 *     with everything a booking write needs: row updates, inserts, SELECT ...
 *     FOR UPDATE and the foreign-key checks of child tables. So if the locks
 *     are granted, no booking transaction is in flight, and any that starts
 *     now queues behind this one. If they cannot be granted in time the apply
 *     is refused: quiescence is proven by holding the locks, never assumed
 *     from row locks (which do not stop inserts).
 *  2. RE-PLAN under the locks, and require the fingerprint the operator took
 *     from the dry run. If the data changed since, nothing is applied.
 *  3. WRITE the markers and counters. slots_max is never written, and booking
 *     rows are changed only in capacity_reserved_date (not even updated_at).
 *  4. VERIFY, still inside the transaction: a fresh plan must be empty, an
 *     independent SQL cross-check must agree, slots_max and every other booking
 *     column must be identical to before. Any failure rolls everything back.
 *
 * It refuses outright, changing nothing, if any accepted booking has no
 * scheduled date: a date is never guessed.
 */
class CapacityReconciler
{
    public function __construct(protected CapacityReconciliationService $reconciliation)
    {
    }

    /**
     * @return array{set:int,moved:int,cleared:int,counters:int,changed:bool}
     *
     * @throws CapacityReconciliationRefused when refused or rolled back; nothing was changed
     */
    public function apply(string $confirmedFingerprint, int $lockTimeoutMs = 5000): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new CapacityReconciliationRefused('Applying the reconciliation is only supported on PostgreSQL.');
        }

        try {
            return DB::transaction(fn () => $this->applyLocked($confirmedFingerprint, max(100, $lockTimeoutMs)));
        } catch (CapacityReconciliationRefused $e) {
            throw $e;
        } catch (Throwable $e) {
            // Class and SQLSTATE only: a database message can echo row data.
            Log::error('Capacity reconciliation failed and was rolled back.', ['error' => class_basename($e), 'sqlstate' => $e instanceof QueryException ? $e->getCode() : null]);

            throw new CapacityReconciliationRefused('The reconciliation failed and was rolled back. Nothing was changed.', 0, $e);
        }
    }

    protected function applyLocked(string $confirmedFingerprint, int $lockTimeoutMs): array
    {
        $this->proveQuiesced($lockTimeoutMs);

        $plan = $this->reconciliation->plan();

        if ($plan['blockers']) {
            throw new CapacityReconciliationRefused('Refusing to apply: ' . implode(' ', $plan['blockers']));
        }

        if (! hash_equals($plan['fingerprint'], $confirmedFingerprint)) {
            throw new CapacityReconciliationRefused('Refusing to apply: the data differs from the dry run you confirmed (plan ' . substr($plan['fingerprint'], 0, 12) . ' now, ' . substr($confirmedFingerprint, 0, 12) . ' confirmed). Run the dry run again.');
        }

        $changes = $plan['changes'];

        if (! array_filter($changes)) {
            return ['set' => 0, 'moved' => 0, 'cleared' => 0, 'counters' => 0, 'changed' => false];
        }

        $before = $this->snapshot();

        $this->writeMarkers($changes);
        $this->writeCounters($changes['counters']);
        $this->verify($before);

        AuditLog::create([
            'user_id' => null,
            'action' => 'capacity_reconciled',
            'entity_type' => 'BookingCapacity',
            'entity_id' => null,
            'reference' => 'capacity reconciliation',
            'description' => sprintf(
                'Scheduled capacity reconciled: %d marker(s) set, %d moved to the current date, %d cleared, %d counter(s) corrected.',
                count($changes['set']), count($changes['move']), count($changes['clear']), count($changes['counters']),
            ),
        ]);

        return [
            'set' => count($changes['set']),
            'moved' => count($changes['move']),
            'cleared' => count($changes['clear']),
            'counters' => count($changes['counters']),
            'changed' => true,
        ];
    }

    protected function proveQuiesced(int $lockTimeoutMs): void
    {
        DB::statement('SET LOCAL lock_timeout = ' . (int) $lockTimeoutMs);

        try {
            DB::statement('LOCK TABLE bookings IN EXCLUSIVE MODE');
            DB::statement('LOCK TABLE booking_capacity IN EXCLUSIVE MODE');
        } catch (QueryException $e) {
            if ($e->getCode() === '55P03') {
                throw new CapacityReconciliationRefused('Refusing to apply: booking writes are in progress, so they cannot be proven quiesced (could not lock the bookings and booking_capacity tables within ' . $lockTimeoutMs . ' ms). Nothing was changed. Retry when no booking activity is running.');
            }

            throw $e;
        }
    }

    /** What must be identical after the apply, apart from the markers and counters being corrected. */
    protected function snapshot(): array
    {
        $bookings = DB::selectOne("SELECT count(*) AS n, md5(coalesce(string_agg((to_jsonb(b) - 'capacity_reserved_date')::text, '|' ORDER BY id), '')) AS h FROM bookings b");

        return [
            'bookings' => $bookings->n . ':' . $bookings->h,
            'slots_max' => DB::table('booking_capacity')->orderBy('booking_date')->pluck('slots_max', 'booking_date')->map(fn ($v) => (int) $v)->all(),
        ];
    }

    protected function writeMarkers(array $changes): void
    {
        $byDate = collect($changes['set'])->map(fn ($c, $id) => ['id' => $id, 'to' => $c['to']])
            ->concat(collect($changes['move'])->map(fn ($c, $id) => ['id' => $id, 'to' => $c['to']]))
            ->groupBy('to');

        foreach ($byDate as $date => $items) {
            foreach ($items->pluck('id')->chunk(500) as $ids) {
                DB::table('bookings')->whereIn('id', $ids->all())->update(['capacity_reserved_date' => $date]);
            }
        }

        foreach (collect(array_keys($changes['clear']))->chunk(500) as $ids) {
            DB::table('bookings')->whereIn('id', $ids->all())->update(['capacity_reserved_date' => null]);
        }
    }

    /** slots_used (and updated_at) only; a missing row is created with the column default for slots_max. */
    protected function writeCounters(array $counters): void
    {
        foreach ($counters as $date => $change) {
            DB::statement(
                'INSERT INTO booking_capacity (booking_date, slots_used, created_at, updated_at) VALUES (?, ?, NOW(), NOW())
                 ON CONFLICT (booking_date) DO UPDATE SET slots_used = EXCLUDED.slots_used, updated_at = NOW()',
                [$date, $change['to']]
            );
        }
    }

    protected function verify(array $before): void
    {
        $fail = fn (string $what) => throw new CapacityReconciliationRefused("Verification failed ({$what}); everything was rolled back. Nothing was changed.");

        $after = $this->reconciliation->plan();
        if (array_filter($after['changes']) || $after['blockers']) {
            $fail('the data is not in the normalized state after the update');
        }

        // Independent SQL cross-check of the same rule, not sharing the planner's code.
        $mismatch = DB::selectOne("
            SELECT count(*) AS n FROM (
                SELECT c.booking_date FROM booking_capacity c
                LEFT JOIN (SELECT capacity_reserved_date AS d, count(*) AS n FROM bookings WHERE capacity_reserved_date IS NOT NULL GROUP BY 1) m ON m.d = c.booking_date
                WHERE c.slots_used <> COALESCE(m.n, 0) OR c.slots_used < 0
                UNION ALL
                SELECT b.capacity_reserved_date FROM bookings b
                WHERE b.capacity_reserved_date IS NOT NULL AND (
                    b.service_type <> 'schedule' OR b.price_locked_at IS NULL OR b.status IN ('cancelled','rejected','not_responding')
                    OR b.capacity_reserved_date IS DISTINCT FROM b.scheduled_date
                    OR NOT EXISTS (SELECT 1 FROM booking_capacity c WHERE c.booking_date = b.capacity_reserved_date))
                UNION ALL
                SELECT b.scheduled_date FROM bookings b
                WHERE b.service_type = 'schedule' AND b.price_locked_at IS NOT NULL AND b.status NOT IN ('cancelled','rejected','not_responding')
                  AND b.scheduled_date IS NOT NULL AND b.capacity_reserved_date IS NULL
            ) problems");
        if ((int) $mismatch->n !== 0) {
            $fail((int) $mismatch->n . ' date(s) or booking(s) still disagree');
        }

        $now = $this->snapshot();
        if ($now['bookings'] !== $before['bookings']) {
            $fail('booking data other than the marker changed');
        }

        foreach ($before['slots_max'] as $date => $max) {
            if (($now['slots_max'][$date] ?? null) !== $max) {
                $fail("slots_max changed for {$date}");
            }
        }
    }
}
