<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only comparison of booking_capacity with the desired accounting state,
 * and the exact set of changes that would bring the data to that state. The
 * dry run, the report and the apply step all use plan(), so what is previewed
 * is what is applied.
 *
 * DESIRED (NORMALIZED) STATE
 *   An eligible booking is a scheduled booking whose quotation was accepted and
 *   price locked (price_locked_at set) and that has not been cancelled, rejected
 *   or marked not responding. A completed booking is eligible: its capacity was
 *   consumed and stays counted. Each eligible booking carries
 *   capacity_reserved_date = its CURRENT scheduled_date, and each date's counter
 *   equals the number of eligible bookings on that date. Historical dates are
 *   not reconstructed: a rescheduled booking counts on the date it has now.
 *
 * HISTORICAL OBSERVATIONS (informational, never acted on)
 *   Reschedule history, accepted bookings that later ended, and similar facts
 *   that explain how the counters drifted. They never change the target.
 *
 * Nothing here writes. report() additionally runs inside a READ ONLY
 * transaction on PostgreSQL, so even a mistake could not change data.
 */
class CapacityReconciliationService
{
    public const ENDED_STATUSES = ['cancelled', 'rejected', 'not_responding'];

    /** @return array<string,mixed> */
    public function report(): array
    {
        // READ ONLY applies to the whole transaction it is set in, so only claim it when this call
        // opens that transaction; inside someone else's it would silently make their later writes fail.
        $outermost = DB::transactionLevel() === 0;

        return DB::transaction(function () use ($outermost) {
            if ($outermost && DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }

            return $this->plan();
        });
    }

    /**
     * Everything needed to preview or apply the reconciliation. Pure
     * computation: the caller decides which transaction and locks it runs in.
     *
     * @return array<string,mixed>
     */
    public function plan(): array
    {
        if (! Schema::hasTable('booking_capacity')) {
            return $this->empty();
        }

        $hasMarker = Schema::hasColumn('bookings', 'capacity_reserved_date');
        $marker = fn ($b) => $hasMarker && $b->capacity_reserved_date !== null ? $b->capacity_reserved_date->toDateString() : null;

        $stored = DB::table('booking_capacity')->get()->keyBy(fn ($row) => (string) $row->booking_date);

        $columns = ['id', 'booking_code', 'status', 'scheduled_date', 'group_code', 'price_locked_at', 'service_type'];
        $scheduled = Booking::query()
            ->where('service_type', 'schedule')
            ->orderBy('id')
            ->get($hasMarker ? array_merge($columns, ['capacity_reserved_date']) : $columns);

        $accepted = $scheduled->whereNotNull('price_locked_at');
        $eligible = $accepted->reject(fn ($b) => in_array($b->status, self::ENDED_STATUSES, true));
        $eligibleDated = $eligible->whereNotNull('scheduled_date');
        $ended = $accepted->filter(fn ($b) => in_array($b->status, self::ENDED_STATUSES, true));

        // ---- Desired state: marker = current scheduled_date; counter = eligible bookings on that date.
        $targetByDate = $eligibleDated->groupBy(fn ($b) => $b->scheduled_date->toDateString())->map->count();

        $setMarkers = [];
        $moveMarkers = [];
        foreach ($eligibleDated as $b) {
            $to = $b->scheduled_date->toDateString();
            $from = $marker($b);

            if ($from === null) {
                $setMarkers[$b->id] = ['code' => $b->booking_code, 'to' => $to];
            } elseif ($from !== $to) {
                $moveMarkers[$b->id] = ['code' => $b->booking_code, 'from' => $from, 'to' => $to];
            }
        }

        // Markers on bookings that must not hold capacity: ended, never accepted, or not a scheduled booking.
        $clearMarkers = [];
        if ($hasMarker) {
            $keepIds = $eligible->pluck('id')->flip();   // eligible (even without a date): never cleared on a guess
            Booking::query()->whereNotNull('capacity_reserved_date')->orderBy('id')
                ->get(['id', 'booking_code', 'status', 'service_type', 'price_locked_at', 'capacity_reserved_date'])
                ->each(function ($b) use (&$clearMarkers, $keepIds) {
                    if (! $keepIds->has($b->id)) {
                        $clearMarkers[$b->id] = [
                            'code' => $b->booking_code,
                            'from' => $b->capacity_reserved_date->toDateString(),
                            'reason' => in_array($b->status, self::ENDED_STATUSES, true) ? 'ended' : 'not_accepted',
                        ];
                    }
                });
        }

        // ---- Counters.
        $dates = $stored->keys()->merge($targetByDate->keys())->unique()->sort()->values();
        $counterChanges = [];
        $rows = [];
        foreach ($dates as $date) {
            $row = $stored->get($date);
            $storedUsed = $row ? (int) $row->slots_used : null;
            $expected = (int) ($targetByDate[$date] ?? 0);
            $difference = ($storedUsed ?? 0) - $expected;
            $holders = $eligibleDated->filter(fn ($b) => $b->scheduled_date->toDateString() === $date);

            if (($storedUsed ?? 0) !== $expected && ! ($storedUsed === null && $expected === 0)) {
                $counterChanges[$date] = ['from' => $storedUsed, 'to' => $expected];
            }

            $rows[] = [
                'date' => $date,
                'stored' => $storedUsed,
                'expected' => $expected,
                'difference' => $difference,
                'status' => match (true) {
                    $row === null => 'missing_row',
                    $storedUsed < 0 => 'negative',
                    $difference > 0 => 'stored_too_high',
                    $difference < 0 => 'stored_too_low',
                    default => 'ok',
                },
                'slots_max' => $row ? (int) $row->slots_max : null,
                'marked_holders' => $holders->filter(fn ($b) => $marker($b) === $date)->count(),
                'legacy_holders' => $holders->filter(fn ($b) => $marker($b) === null)->count(),
                'completed_holders' => $holders->where('status', 'completed')->count(),
                'holding_bookings' => $holders->pluck('booking_code')->all(),
                'accepted_then_ended' => $ended->filter(fn ($b) => $b->scheduled_date?->toDateString() === $date)->count(),
            ];
        }

        // ---- Historical observations (never change the target).
        $rescheduledIds = AuditLog::query()
            ->where('action', 'booking_rescheduled')->where('entity_type', 'Booking')
            ->pluck('entity_id')->map(fn ($id) => (int) $id)->flip();

        $unmarked = $eligibleDated->filter(fn ($b) => $marker($b) === null);
        $legacy = $unmarked->map(fn ($b) => [
            'booking_id' => $b->id,
            'booking_code' => $b->booking_code,
            'status' => $b->status,
            'scheduled_date' => $b->scheduled_date->toDateString(),
            'completed' => $b->status === 'completed',
            'rescheduled' => $rescheduledIds->has($b->id),   // historical observation only
            'group_code' => $b->group_code,
            'suggested_marker' => $b->scheduled_date->toDateString(),   // normalized: always the current date
        ])->values()->all();

        $anomalies = [
            'eligible_without_date' => $eligible->whereNull('scheduled_date')->map(fn ($b) => ['booking_code' => $b->booking_code, 'status' => $b->status])->values()->all(),
            'ended_with_marker' => collect($clearMarkers)->where('reason', 'ended')->map(fn ($c) => ['booking_code' => $c['code'], 'marker' => $c['from']])->values()->all(),
            'marker_without_acceptance' => collect($clearMarkers)->where('reason', 'not_accepted')->map(fn ($c) => ['booking_code' => $c['code'], 'marker' => $c['from']])->values()->all(),
            'marker_differs_from_date' => collect($moveMarkers)->map(fn ($m) => ['booking_code' => $m['code'], 'marker' => $m['from'], 'scheduled_date' => $m['to']])->values()->all(),
            'group_mixed_acceptance' => $this->mixedAcceptanceGroups($scheduled),
            'group_split_dates' => $this->splitDateGroups($eligibleDated),
        ];

        $observations = [
            'rescheduled_legacy' => $unmarked->filter(fn ($b) => $rescheduledIds->has($b->id))->pluck('booking_code')->values()->all(),
            'completed_consumed' => $eligibleDated->where('status', 'completed')->count(),
            'accepted_then_ended' => $ended->count(),
        ];

        $changes = [
            'set' => $setMarkers,
            'move' => $moveMarkers,
            'clear' => $clearMarkers,
            'counters' => $counterChanges,
        ];

        $blockers = [];
        if (! $hasMarker) {
            $blockers[] = 'The capacity_reserved_date column does not exist yet: run the migration first.';
        }
        foreach ($anomalies['eligible_without_date'] as $row) {
            $blockers[] = "Booking {$row['booking_code']} is accepted but has no scheduled date; a date will not be guessed. Resolve it first.";
        }

        return [
            'dates' => $rows,
            'legacy' => $legacy,
            'anomalies' => $anomalies,
            'observations' => $observations,
            'changes' => $changes,
            'blockers' => $blockers,
            'fingerprint' => $this->fingerprint($changes),
            'summary' => $this->summary($rows, $legacy, $anomalies, $observations, $changes, $blockers),
        ];
    }

    /** Identifies exactly this set of changes; apply must be given the value the dry run printed. */
    public function fingerprint(array $changes): string
    {
        $canonical = fn (array $items) => collect($items)->sortKeys()->all();

        return sha1(json_encode([
            'set' => $canonical($changes['set']),
            'move' => $canonical($changes['move']),
            'clear' => $canonical($changes['clear']),
            'counters' => $canonical($changes['counters']),
        ], JSON_THROW_ON_ERROR));
    }

    /** Groups where some members were accepted while another member is still waiting (not ended, not accepted). */
    protected function mixedAcceptanceGroups(Collection $scheduled): array
    {
        return $scheduled->whereNotNull('group_code')->groupBy('group_code')
            ->filter(function (Collection $members) {
                $hasAccepted = $members->contains(fn ($b) => $b->price_locked_at !== null && ! in_array($b->status, self::ENDED_STATUSES, true));
                $hasPending = $members->contains(fn ($b) => $b->price_locked_at === null && ! in_array($b->status, self::ENDED_STATUSES, true));

                return $hasAccepted && $hasPending;
            })
            ->map(fn (Collection $members, $group) => ['group_code' => $group, 'bookings' => $members->pluck('booking_code')->all()])
            ->values()->all();
    }

    /** Groups whose accepted vehicles sit on different dates (allowed; informational). */
    protected function splitDateGroups(Collection $eligibleDated): array
    {
        return $eligibleDated->whereNotNull('group_code')->groupBy('group_code')
            ->filter(fn (Collection $members) => $members->map(fn ($b) => $b->scheduled_date->toDateString())->unique()->count() > 1)
            ->map(fn (Collection $members, $group) => ['group_code' => $group, 'dates' => $members->map(fn ($b) => $b->scheduled_date->toDateString())->unique()->sort()->values()->all()])
            ->values()->all();
    }

    protected function empty(): array
    {
        $anomalies = ['eligible_without_date' => [], 'ended_with_marker' => [], 'marker_without_acceptance' => [], 'marker_differs_from_date' => [], 'group_mixed_acceptance' => [], 'group_split_dates' => []];
        $changes = ['set' => [], 'move' => [], 'clear' => [], 'counters' => []];
        $observations = ['rescheduled_legacy' => [], 'completed_consumed' => 0, 'accepted_then_ended' => 0];

        return [
            'dates' => [], 'legacy' => [], 'anomalies' => $anomalies, 'observations' => $observations, 'changes' => $changes,
            'blockers' => ['The booking_capacity table does not exist.'], 'fingerprint' => $this->fingerprint($changes),
            'summary' => $this->summary([], [], $anomalies, $observations, $changes, ['no table']),
        ];
    }

    protected function summary(array $rows, array $legacy, array $anomalies, array $observations, array $changes, array $blockers): array
    {
        $dates = collect($rows);
        $legacyRows = collect($legacy);
        $count = fn (string $status) => $dates->where('status', $status)->count();

        return [
            // Counters, by date (desired state vs what is stored).
            'dates_checked' => $dates->count(),
            'ok' => $count('ok'),
            'stored_too_high' => $count('stored_too_high'),
            'stored_too_low' => $count('stored_too_low'),
            'negative' => $count('negative'),
            'missing_row' => $count('missing_row'),
            'orphan_counters' => $dates->filter(fn ($r) => $r['expected'] === 0 && ($r['stored'] ?? 0) > 0)->count(),
            // Bookings.
            'legacy_active_unmarked' => $legacyRows->where('completed', false)->count(),
            'legacy_completed_unmarked' => $legacyRows->where('completed', true)->count(),
            'legacy_rescheduled' => $legacyRows->where('rescheduled', true)->count(),
            'completed_consumed' => $observations['completed_consumed'],
            'eligible_without_date' => count($anomalies['eligible_without_date']),
            'ended_with_marker' => count($anomalies['ended_with_marker']),
            'marker_without_acceptance' => count($anomalies['marker_without_acceptance']),
            'marker_differs_from_date' => count($anomalies['marker_differs_from_date']),
            'group_mixed_acceptance' => count($anomalies['group_mixed_acceptance']),
            'group_split_dates' => count($anomalies['group_split_dates']),
            // What an apply would do.
            'markers_to_set' => count($changes['set']),
            'markers_to_move' => count($changes['move']),
            'markers_to_clear' => count($changes['clear']),
            'counters_to_change' => count($changes['counters']),
            'blockers' => count($blockers),
        ];
    }
}
