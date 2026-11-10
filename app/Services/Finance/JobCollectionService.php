<?php

namespace App\Services\Finance;

use App\Models\Booking;
use App\Models\Finance\Expense;
use App\Models\Finance\JobPersonnel;
use App\Models\Finance\RevenueAllocation;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\Quotation;
use App\Models\Unit;
use App\Models\User;
use App\Support\Finance\FinanceAccess;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Jobs & Collections: one row per JOB (a single booking, or a normalized group trip = several vehicle bookings
 * that share group_code + pickup + dropoff, as Invoice::sameTripMembers() defines it). Read-only over the existing
 * booking, invoice and payment records. No customer payment is ever copied.
 *
 * Authoritative verified-payment source (see ReportMetricsService::PAYMENT_INVARIANT):
 *   - Dispatcher confirmation: Admin\JobsController::confirmPayment() sets status=completed + completed_at after the
 *     Team Leader submitted payment (cash must cover the total). That verifies the FULL agreed charge.
 *   - A LIVE PayMongo payment (status paid, livemode=true) on a non-demo booking is provider-verified money received.
 *     It counts before dispatcher confirmation (this is what makes a partial collection visible).
 *   NOT collections: the customer's preferred method, a TL payment submission awaiting verification, cash tendered
 *   (cash_received includes change), PayMongo TEST payments, pending/failed/expired transactions, review/duplicate
 *   transactions (refund review), demo bookings, and a status manually overridden to "completed" without completed_at.
 *
 * Agreed charge: the current, non-voided invoice total when one exists (authoritative), otherwise the group formula
 * (active members' final_total + quotation fee − discount, never below zero), otherwise the booking's final_total.
 *
 * Unit allocation: when the job is fully collected, each vehicle's collection goes to the unit assigned to that
 * vehicle booking (a group's fee/discount is shared in proportion to each vehicle's own charge). A job one unit
 * did alone needs no decision. Anything else stays PENDING REVIEW until the Owner records a split: partial or
 * over-collection, a vehicle with no unit, or signs that another unit helped. Nothing is split 50:50 automatically.
 *
 * Personnel come only from records written at the time (finance_job_personnel, and the booking's own assigned
 * Team Leader and driver name). A unit's CURRENT crew is never used for a past job.
 */
class JobCollectionService
{
    public const SERVICE_DONE_STATUSES = ['completed', 'waiting_verification', 'payment_pending', 'payment_submitted'];
    public const AWAITING_STATUSES = ['waiting_verification', 'payment_pending', 'payment_submitted'];

    public const COLLECTION_STATUSES = [
        'paid' => 'Paid in full',
        'partial' => 'Partially paid',
        'awaiting_verification' => 'Payment awaiting verification',
        'completed_unverified' => 'Completed, payment not verified',
        'unpaid' => 'Unpaid',
        'overpaid' => 'Over-collected (review)',
    ];

    public const ALLOCATION_STATUSES = [
        'automatic' => 'Allocated automatically',
        'manual' => 'Split recorded by Owner',
        'pending_review' => 'Allocation pending review',
        'nothing_collected' => 'Nothing collected yet',
    ];

    private array $quotationCache = [];

    // ------------------------------------------------------------------ listing

    /**
     * @param array{unit_id?:?int, booking?:?string, status?:?string, method?:?string, allocation?:?string, basis?:string} $filters
     * @return Collection<int, array>
     */
    public function jobs(CarbonImmutable $from, CarbonImmutable $to, array $filters = []): Collection
    {
        $basis = ($filters['basis'] ?? 'service') === 'payment' ? 'payment' : 'service';
        $fromTs = $from->startOfDay();
        $toTs = $to->endOfDay();

        $candidates = Booking::query()
            ->excludingDemo()
            ->whereIn('status', self::SERVICE_DONE_STATUSES)
            ->where(function ($q) use ($basis, $fromTs, $toTs) {
                if ($basis === 'service') {
                    $q->whereRaw(self::serviceAtSql() . ' BETWEEN ? AND ?', [$fromTs, $toTs]);
                } else {
                    $q->whereBetween('completed_at', [$fromTs, $toTs])
                        ->orWhereIn('id', PaymentTransaction::query()->select('booking_id')
                            ->where('status', PaymentTransaction::STATUS_PAID)->where('livemode', true)
                            ->whereBetween('paid_at', [$fromTs, $toTs]));
                }
            })
            ->get();

        $jobs = $this->groupIntoJobs($candidates)->map(fn (Collection $members) => $this->build($members));

        return $jobs
            ->filter(function (array $job) use ($basis, $from, $to) {
                $date = $basis === 'service' ? $job['service_date'] : $job['payment_date'];

                return $date !== null && $date->betweenIncluded($from->startOfDay(), $to->endOfDay());
            })
            ->filter(fn (array $job) => $this->matches($job, $filters))
            ->sortByDesc(fn (array $job) => [$job['service_date']?->timestamp ?? 0, $job['anchor']->id])
            ->values();
    }

    /** One job by any of its booking ids (used by the split form). */
    public function jobFor(Booking $booking): array
    {
        return $this->build($this->membersOf($booking));
    }

    /** Totals over a list of jobs: counts and exact money. */
    public function totals(Collection $jobs): array
    {
        $byUnit = [];
        foreach ($jobs as $job) {
            foreach ($job['allocation']['by_unit'] as $unitId => $row) {
                $byUnit[$unitId] ??= ['unit_id' => $unitId, 'unit_name' => $row['unit_name'], 'amount' => Money::zero(), 'jobs' => 0, 'vehicles' => 0];
                $byUnit[$unitId]['amount'] = $byUnit[$unitId]['amount']->plus($row['amount']);
                $byUnit[$unitId]['jobs']++;
                $byUnit[$unitId]['vehicles'] += $row['vehicles'];
            }
        }

        return [
            'bookings' => $jobs->count(),
            'completed_bookings' => $jobs->where('service_completed', true)->count(),
            'vehicles' => (int) $jobs->sum('vehicles'),
            'agreed' => Money::sum($jobs->pluck('agreed')),
            'collected' => Money::sum($jobs->pluck('collected')),
            'outstanding' => Money::sum($jobs->map(fn ($j) => $j['outstanding']->isPositive() ? $j['outstanding'] : Money::zero())),
            'over_collected' => Money::sum($jobs->map(fn ($j) => $j['outstanding']->isNegative() ? Money::zero()->minus($j['outstanding']) : Money::zero())),
            'allocated' => Money::sum(collect($byUnit)->pluck('amount')),
            'pending_allocations' => $jobs->where('allocation.status', 'pending_review')->count(),
            'by_unit' => collect($byUnit)->sortBy('unit_name')->values(),
        ];
    }

    // ------------------------------------------------------------------ manual split

    /**
     * Records the Owner's split of one job's collection between towing units. The amounts must add up EXACTLY to the
     * job's allocatable collection. A previous split is superseded (kept in history), never overwritten.
     *
     * @param  array<int|string, string>  $amounts  unit_id => amount
     */
    public function recordSplit(User $actor, Booking $booking, array $amounts, ?string $note = null): Collection
    {
        \Illuminate\Support\Facades\Gate::forUser($actor)->authorize(FinanceAccess::ALLOCATE_REVENUE);

        return DB::transaction(function () use ($actor, $booking, $amounts, $note) {
            Booking::whereKey($booking->id)->lockForUpdate()->first();
            $job = $this->jobFor($booking);

            if (count($job['members']) !== 1) {
                throw new FinanceException('Group trips are allocated vehicle by vehicle to each assigned unit. Splitting a group trip by hand is not supported yet.');
            }
            $allocatable = $job['allocatable'];
            if (! $allocatable->isPositive()) {
                throw new FinanceException('There is no verified collection to allocate for this job yet.');
            }

            $parsed = [];
            foreach ($amounts as $unitId => $value) {
                if ($value === null || trim((string) $value) === '') {
                    continue;
                }
                try {
                    $money = Money::of(str_replace([',', '₱', ' '], '', (string) $value));
                } catch (\InvalidArgumentException) {
                    throw new FinanceException('Enter each unit amount with at most two decimal places.');
                }
                if (! $money->isPositive()) {
                    throw new FinanceException('Each unit amount must be greater than zero.');
                }
                if (! Unit::whereKey((int) $unitId)->exists()) {
                    throw new FinanceException('One of the selected units does not exist.');
                }
                $parsed[(int) $unitId] = $money;
            }

            if (count($parsed) < 1) {
                throw new FinanceException('Enter the amount for at least one unit.');
            }
            $sum = Money::sum($parsed);
            if (! $sum->equals($allocatable)) {
                throw new FinanceException("The unit amounts ({$sum->format()}) must add up exactly to the verified collection to allocate ({$allocatable->format()}).");
            }

            $member = $job['members'][0];
            $previous = RevenueAllocation::current()->where('booking_id', $member->id)->lockForUpdate()->get();
            $now = now();
            foreach ($previous as $old) {
                $old->forceFill(['superseded_at' => $now])->save();
            }

            $vatRatio = $this->vatRatio($member);
            $rows = collect();
            foreach ($parsed as $unitId => $gross) {
                $vat = $vatRatio ? $gross->shareOf($vatRatio[0], $vatRatio[1]) : Money::zero();
                $rows->push(RevenueAllocation::create([
                    'booking_id' => $member->id,
                    'unit_id' => $unitId,
                    'gross_amount' => $gross->toString(),
                    'vat_amount' => $vat->toString(),
                    'net_amount' => $gross->minus($vat)->toString(),
                    'allocatable_total' => $allocatable->toString(),
                    'allocation_method' => 'manual_split',
                    'notes' => $note ? mb_substr(trim($note), 0, 1000) : null,
                    'invoice_id' => $job['invoice']?->id,
                    'collected_at' => $job['payment_date'] ?? $now,
                    'computed_by' => $actor->id,
                ]));
            }

            // Point each superseded row at the first replacement so the history chain is traceable.
            foreach ($previous as $old) {
                $old->forceFill(['superseded_by_id' => $rows->first()->id])->save();
            }

            \App\Models\AuditLog::create([
                'user_id' => $actor->id,
                'actor_label' => $actor->full_name ?: $actor->name,
                'action' => 'finance_allocation_recorded',
                'entity_type' => 'Booking',
                'entity_id' => $member->id,
                'reference' => $member->booking_code,
                'description' => 'Recorded unit revenue split of ' . $allocatable->format() . ' for job ' . $member->booking_code,
                'new_value' => ['split' => collect($parsed)->map->toString()->all(), 'note' => $note],
            ]);

            return $rows;
        });
    }

    // ------------------------------------------------------------------ building a job

    /** @param Collection<int, Booking> $candidates  @return Collection<string, Collection<int, Booking>> */
    private function groupIntoJobs(Collection $candidates): Collection
    {
        $jobs = collect();
        $seen = [];
        foreach ($candidates->sortBy('id') as $booking) {
            if (isset($seen[$booking->id])) {
                continue;
            }
            $members = $this->membersOf($booking);
            foreach ($members as $m) {
                $seen[$m->id] = true;
            }
            $jobs->put($this->jobKey($booking), $members);
        }

        return $jobs;
    }

    /** Active (not cancelled or rejected) bookings of the job, lowest id first. */
    private function membersOf(Booking $booking): Collection
    {
        if (! Invoice::isNormalizedGroupTransaction($booking)) {
            return collect([$booking]);
        }

        return Invoice::sameTripMembers($booking)
            ->excludingDemo()
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->orderBy('id')
            ->get();
    }

    private function jobKey(Booking $booking): string
    {
        return Invoice::isNormalizedGroupTransaction($booking)
            ? 'g:' . md5($booking->group_code . '|' . $booking->pickup_address . '|' . $booking->dropoff_address)
            : 'b:' . $booking->id;
    }

    private function build(Collection $members): array
    {
        $members = new \Illuminate\Database\Eloquent\Collection($members->values()->all());
        $members->loadMissing(['customer:id,full_name,phone', 'unit:id,name,plate_number', 'assignedTeamLeader:id,name,first_name,middle_name,last_name', 'truckType:id,name']);
        $anchor = $members->first();
        $ids = $members->pluck('id')->all();
        $isGroup = count($ids) > 1 || Invoice::isNormalizedGroupTransaction($anchor);

        // Agreed charge.
        $invoice = Invoice::whereIn('booking_id', $ids)->where('is_current', true)->whereNull('voided_at')->latest('id')->first();
        if ($invoice) {
            $agreed = Money::of((string) $invoice->total);
            $agreedSource = 'invoice';
        } elseif ($isGroup) {
            $agreed = $this->groupFormulaTotal($anchor, $members);
            $agreedSource = 'group_formula';
        } else {
            $agreed = Money::of((string) ($anchor->final_total ?? '0'));
            $agreedSource = 'booking';
        }

        // Vehicles: a normalized group has one booking per vehicle; a single booking may still list extra vehicles.
        $vehicles = $isGroup
            ? count($ids)
            : 1 + collect($anchor->extra_vehicles ?? [])->filter(fn ($ev) => empty($ev['booking_id']))->count();

        // Payment evidence.
        $transactions = PaymentTransaction::whereIn('booking_id', $ids)->orderBy('id')->get()->unique('id');
        $livePaid = $transactions->filter(fn ($t) => $t->status === PaymentTransaction::STATUS_PAID && $t->livemode);
        $onlineVerified = Money::sum($livePaid->map(fn ($t) => Money::fromCentavos((int) $t->amount_centavos)));
        $testPaid = $transactions->filter(fn ($t) => $t->status === PaymentTransaction::STATUS_PAID && ! $t->livemode);
        $refundReview = $transactions->filter(fn ($t) => in_array($t->status, [PaymentTransaction::STATUS_REVIEW, PaymentTransaction::STATUS_DUPLICATE], true)
            || in_array($t->flag, ['cash_also_collected', 'duplicate_payment', 'second_payment_same_session'], true));

        $confirmed = $members->every(fn ($m) => $m->status === 'completed' && $m->completed_at !== null);
        $overriddenComplete = ! $confirmed && $members->contains(fn ($m) => $m->status === 'completed' && $m->completed_at === null);
        $awaiting = ! $confirmed && $members->contains(fn ($m) => in_array($m->status, self::AWAITING_STATUSES, true) || ($m->payment_submitted_at !== null && $m->completed_at === null));

        $collected = $confirmed ? $agreed->max($onlineVerified) : $onlineVerified;
        $outstanding = $agreed->minus($collected);

        $status = match (true) {
            $collected->compareTo($agreed) > 0 => 'overpaid',
            $confirmed || ($collected->isPositive() && $collected->equals($agreed)) => 'paid',
            $collected->isPositive() => 'partial',
            $awaiting => 'awaiting_verification',
            $overriddenComplete => 'completed_unverified',
            default => 'unpaid',
        };

        // The method the Team Leader recorded, and cash handed over (repeated on each group member: read once).
        $method = $anchor->payment_method;
        $cashTendered = $method === 'cash' ? $members->pluck('cash_received')->filter(fn ($v) => $v !== null)->first() : null;
        $cashTendered = $cashTendered !== null ? Money::of((string) $cashTendered) : null;
        $change = $cashTendered && $cashTendered->compareTo($agreed) > 0 ? $cashTendered->minus($agreed) : Money::zero();

        $serviceAt = $members->map(fn ($m) => $this->serviceAt($m))->filter()->max();
        $paymentAt = $confirmed
            ? $members->pluck('completed_at')->filter()->max()
            : $livePaid->pluck('paid_at')->filter()->max();
        $serviceDate = $serviceAt ? CarbonImmutable::parse($serviceAt, config('finance.timezone')) : null;
        $paymentDate = $paymentAt ? CarbonImmutable::parse($paymentAt, config('finance.timezone')) : null;

        $flags = [];
        if ($serviceDate?->isSunday()) {
            $flags['sunday'] = 'Service on a Sunday (outside the Monday–Saturday reporting week)';
        }
        if ($serviceDate && $paymentDate && $this->weekStart($paymentDate)->greaterThan($this->weekStart($serviceDate))) {
            $flags['late_payment'] = 'Paid in a later reporting week (' . $paymentDate->format('M j, Y') . ')';
        }
        if ($testPaid->isNotEmpty()) {
            $flags['test_payment'] = 'PayMongo TEST payment recorded; never counted as a collection';
        }
        if ($refundReview->isNotEmpty()) {
            $flags['refund_review'] = 'Online payment flagged for refund review; refunds are not recorded in TowMate yet';
        }
        if ($status === 'overpaid') {
            $flags['over_collected'] = 'More was collected than the agreed charge (' . Money::zero()->minus($outstanding)->format() . '); the excess is not allocated';
        }
        if ($change->isPositive()) {
            $flags['change'] = 'Cash tendered ' . $cashTendered->format() . ', change ' . $change->format() . ' (change is not revenue)';
        }

        $job = [
            'key' => $this->jobKey($anchor),
            'anchor' => $anchor,
            'members' => $members->all(),
            'is_group' => $isGroup,
            'reference' => $anchor->booking_code,
            'group_code' => $isGroup ? $anchor->group_code : null,
            'customer' => $anchor->customer,
            'service_completed' => $members->every(fn ($m) => in_array($m->status, self::SERVICE_DONE_STATUSES, true)),
            'service_date' => $serviceDate,
            'payment_date' => $status === 'unpaid' || $status === 'awaiting_verification' || $status === 'completed_unverified' ? null : $paymentDate,
            'vehicles' => $vehicles,
            'agreed' => $agreed,
            'agreed_source' => $agreedSource,
            'invoice' => $invoice,
            'collected' => $collected,
            'online_verified' => $onlineVerified,
            'outstanding' => $outstanding,
            'status' => $status,
            'status_label' => self::COLLECTION_STATUSES[$status],
            'confirmed_by_dispatcher' => $confirmed,
            'method' => $method,
            'method_label' => Booking::preferredPaymentMethodLabel($method) ?? ($livePaid->isNotEmpty() ? 'Online (PayMongo)' : null),
            'preferred_method_label' => Booking::preferredPaymentMethodLabel($anchor->preferred_payment_method),
            'cash_tendered' => $cashTendered,
            'change' => $change,
            'transactions' => $transactions->values()->all(),
            'allocatable' => $collected->min($agreed),
            'flags' => $flags,
        ];

        $job['units'] = $this->units($members);
        $job['personnel'] = $this->personnel($members);
        $job['expenses'] = $this->expenses($ids);
        $job['allocation'] = $this->allocation($job, $members);
        if ($job['allocation']['status'] === 'pending_review') {
            $job['flags']['allocation'] = 'Allocation pending review: ' . $job['allocation']['reason'];
        }

        return $job;
    }

    private function units(Collection $members): array
    {
        return $members->map(fn (Booking $m) => [
            'booking_id' => $m->id,
            'booking_code' => $m->booking_code,
            'unit_id' => $m->assigned_unit_id,
            'unit_name' => $m->unit?->name ?? $m->assigned_unit_name ?? null,
            'plate' => $m->unit?->plate_number ?? $m->assigned_unit_plate_number ?? null,
            'truck_type' => $m->truckType?->name,
        ])->all();
    }

    /** Historical crew only: captured job personnel, else the booking's own assignment fields. Never a unit's current crew. */
    private function personnel(Collection $members): array
    {
        $captured = JobPersonnel::current()->whereIn('booking_id', $members->pluck('id'))->orderBy('booking_id')->orderBy('id')->get();
        if ($captured->isNotEmpty()) {
            return [
                'source' => 'captured',
                'people' => $captured->map(fn (JobPersonnel $p) => [
                    'name' => $p->name_snapshot,
                    'slot' => str_replace('_', ' ', $p->slot),
                    'needs_review' => (bool) $p->needs_review,
                ])->all(),
            ];
        }

        $people = [];
        foreach ($members as $m) {
            if ($m->assignedTeamLeader) {
                $people[] = ['name' => $m->assignedTeamLeader->full_name ?: $m->assignedTeamLeader->name, 'slot' => 'team leader', 'needs_review' => false];
            }
            if (filled($m->driver_name)) {
                $people[] = ['name' => $m->driver_name, 'slot' => 'driver', 'needs_review' => false];
            }
        }

        return [
            'source' => $people ? 'booking' : 'none',
            'people' => collect($people)->unique(fn ($p) => $p['slot'] . '|' . $p['name'])->values()->all(),
        ];
    }

    private function expenses(array $bookingIds): array
    {
        $rows = Expense::whereIn('booking_id', $bookingIds)->whereNotIn('status', [Expense::STATUS_REJECTED, Expense::STATUS_VOIDED])->get(['id', 'unit_id', 'amount', 'status', 'category']);
        $approved = $rows->where('status', Expense::STATUS_APPROVED);

        return [
            'approved' => Money::sum($approved->pluck('amount')),
            'pending' => Money::sum($rows->whereIn('status', Expense::PENDING_STATUSES)->pluck('amount')),
            'count' => $rows->count(),
            'by_unit' => $approved->groupBy('unit_id')->map(fn ($g) => Money::sum($g->pluck('amount')))->all(),
            'assistance_unit_ids' => $rows->where('category', 'assistance_other_unit')->pluck('unit_id')->unique()->values()->all(),
        ];
    }

    private function allocation(array $job, Collection $members): array
    {
        $allocatable = $job['allocatable'];
        $unitNames = Unit::whereIn('id', collect($job['units'])->pluck('unit_id')->filter())->pluck('name', 'id');
        $result = fn (string $status, array $lines, ?string $reason = null) => [
            'status' => $status,
            'status_label' => self::ALLOCATION_STATUSES[$status],
            'reason' => $reason,
            'lines' => $lines,
            'by_unit' => collect($lines)->groupBy('unit_id')->map(fn ($g, $unitId) => [
                'unit_name' => $g->first()['unit_name'],
                'amount' => Money::sum($g->pluck('amount')),
                'vehicles' => $g->sum('vehicles'),
            ])->all(),
            'total' => Money::sum(collect($lines)->pluck('amount')),
        ];

        // An Owner split recorded earlier wins while it still matches the current collection.
        $manual = RevenueAllocation::current()->whereIn('booking_id', $members->pluck('id'))->where('allocation_method', 'manual_split')->with('unit:id,name')->get();
        if ($manual->isNotEmpty()) {
            $lines = $manual->map(fn (RevenueAllocation $r) => [
                'unit_id' => $r->unit_id, 'unit_name' => $r->unit?->name, 'amount' => Money::of($r->gross_amount),
                'vehicles' => 0, 'booking_code' => $job['reference'],
            ])->all();
            $savedOn = Money::of((string) $manual->first()->allocatable_total);
            if ($savedOn->equals($allocatable) && Money::sum(collect($lines)->pluck('amount'))->equals($allocatable)) {
                $lines[0]['vehicles'] = $job['vehicles'];

                return $result('manual', $lines);
            }

            return $result('pending_review', [], "the recorded split was based on {$savedOn->format()}, but the verified collection is now {$allocatable->format()}");
        }

        if (! $allocatable->isPositive()) {
            return $result('nothing_collected', []);
        }
        // Partly paid: which unit the partial money belongs to is a decision, not arithmetic.
        // (Over-collection still allocates the agreed charge; the excess is flagged separately.)
        if ($job['collected']->compareTo($job['agreed']) < 0) {
            return $result('pending_review', [], 'the job is only partly paid');
        }
        if (collect($job['units'])->contains(fn ($u) => empty($u['unit_id']))) {
            return $result('pending_review', [], 'a vehicle has no assigned unit');
        }

        // Signs that another unit worked on the job: an "Assistance from Another Unit" expense, or captured crew
        // that came from (or belongs to) a unit other than the one assigned.
        $assignedUnitIds = collect($job['units'])->pluck('unit_id')->unique()->values()->all();
        $borrowed = JobPersonnel::current()->whereIn('booking_id', $members->pluck('id'))
            ->where(fn ($q) => $q->whereNotNull('borrowed_from_unit_id')->orWhere(fn ($w) => $w->whereNotNull('unit_id')->whereNotIn('unit_id', $assignedUnitIds)))
            ->exists();
        if ($job['expenses']['assistance_unit_ids'] !== [] || $borrowed) {
            return $result('pending_review', [], 'another unit appears to have helped on this job');
        }

        // Fully collected, one unit per vehicle: each vehicle's share goes to its own unit.
        $shares = $this->vehicleShares($job, $members, $allocatable);
        $lines = [];
        foreach ($members as $i => $m) {
            $lines[] = [
                'unit_id' => $m->assigned_unit_id,
                'unit_name' => $unitNames[$m->assigned_unit_id] ?? $m->assigned_unit_name,
                'amount' => $shares[$i],
                'vehicles' => $job['is_group'] ? 1 : $job['vehicles'],
                'booking_code' => $m->booking_code,
            ];
        }

        return $result('automatic', $lines);
    }

    /**
     * Each vehicle's share of the allocatable collection, in proportion to its own final_total, summing EXACTLY to it.
     * @return Money[]
     */
    private function vehicleShares(array $job, Collection $members, Money $allocatable): array
    {
        if ($members->count() === 1) {
            return [$allocatable];
        }

        $weights = $members->map(fn ($m) => Money::of((string) ($m->final_total ?? '0')))->values();
        $whole = Money::sum($weights);
        if (! $whole->isPositive()) {
            $weights = $members->map(fn () => Money::of(1))->values(); // all zero-priced: equal weights, still exact
            $whole = Money::sum($weights);
        }

        $shares = $weights->map(fn (Money $w) => $allocatable->shareOf($w, $whole))->all();
        $diff = $allocatable->minus(Money::sum($shares));
        if (! $diff->isZero()) {
            // The rounding centavo(s) go to the vehicle with the largest charge (first one on a tie).
            $largest = 0;
            foreach ($weights as $i => $w) {
                if ($w->compareTo($weights[$largest]) > 0) {
                    $largest = $i;
                }
            }
            $shares[$largest] = $shares[$largest]->plus($diff);
        }

        return $shares;
    }

    private function groupFormulaTotal(Booking $anchor, Collection $members): Money
    {
        $quotation = $anchor->quotation_id ? ($this->quotationCache[$anchor->quotation_id] ??= Quotation::find($anchor->quotation_id)) : null;
        $sum = Money::sum($members->map(fn ($m) => Money::of((string) ($m->final_total ?? '0'))));
        $adjustment = $quotation
            ? Money::of((string) ($quotation->additional_fee ?? '0'))->minus((string) ($quotation->discount ?? '0'))
            : Money::zero();
        $total = $sum->plus($adjustment);

        return $total->isNegative() ? Money::zero() : $total;
    }

    /** @return array{0:string,1:string}|null  VAT part as [vat_amount, final_total] of the booking. */
    private function vatRatio(Booking $booking): ?array
    {
        $vat = (string) ($booking->vat_amount ?? '0');
        $total = (string) ($booking->final_total ?? '0');
        if (bccomp($vat, '0', 2) <= 0 || bccomp($total, '0', 2) <= 0) {
            return null;
        }

        return [Money::of($vat)->toString(), Money::of($total)->toString()];
    }

    private function matches(array $job, array $f): bool
    {
        if (! empty($f['unit_id'])) {
            $units = collect($job['units'])->pluck('unit_id')->merge(array_keys($job['allocation']['by_unit']))->map(fn ($v) => (int) $v);
            if (! $units->contains((int) $f['unit_id'])) {
                return false;
            }
        }
        if (! empty($f['booking'])) {
            // Booking reference or group code, ignoring case, spaces, hyphens and "#" ("tm00148" finds "TM-00148").
            // Every member's own group code counts, even when the group is not a normalized group trip.
            $normalize = fn (?string $v) => preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $v));
            $needle = $normalize($f['booking']);
            $haystack = collect($job['members'])->flatMap(fn ($m) => [$m->booking_code, $m->group_code])->push($job['group_code'])
                ->filter()->map($normalize)->filter()->unique();
            if ($needle !== '' && ! $haystack->contains(fn ($v) => str_contains($v, $needle))) {
                return false;
            }
        }
        if (! empty($f['status'])) {
            if ($f['status'] === 'outstanding' ? ! $job['outstanding']->isPositive() : $job['status'] !== $f['status']) {
                return false;
            }
        }
        if (! empty($f['method']) && $job['method'] !== $f['method']) {
            return false;
        }
        if (! empty($f['allocation']) && $job['allocation']['status'] !== $f['allocation']) {
            return false;
        }

        return true;
    }

    private function serviceAt(Booking $b): mixed
    {
        return $b->completion_requested_at ?? $b->customer_verified_at ?? $b->payment_submitted_at ?? $b->completed_at ?? $b->assigned_at ?? $b->created_at;
    }

    /** SQL twin of serviceAt() for the candidate query (timestamps are stored in Asia/Manila). */
    private static function serviceAtSql(): string
    {
        return 'COALESCE(completion_requested_at, customer_verified_at, payment_submitted_at, completed_at, assigned_at, created_at)';
    }

    private function weekStart(CarbonImmutable $date): CarbonImmutable
    {
        return $date->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }
}
