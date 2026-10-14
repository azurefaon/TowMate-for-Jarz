<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Receipt;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Read-only presentation rules for the Owner Bookings page (list + details).
 *
 * Amounts follow the verified group pricing rules: membership is
 * Invoice::sameTripMembers() (never group_code alone); a sent quotation is
 * priced from its lines; accepted vehicles from their own final_total; an
 * issued invoice total is authoritative; group fee and discount are applied
 * once, from the quotation. Nothing here writes to the database.
 */
class OwnerBookingPresenter
{
    /** Status filter groups, in menu order. */
    public const STATUS_GROUPS = [
        'needs_review' => ['label' => 'Needs review', 'hint' => 'Requested or under review', 'statuses' => ['requested', 'reviewed']],
        'quotation' => ['label' => 'Quotation & Confirmation', 'hint' => 'Quoted, quotation sent, or confirmed', 'statuses' => ['quoted', 'quotation_sent', 'confirmed']],
        'scheduled' => ['label' => 'Scheduled', 'hint' => null, 'statuses' => ['scheduled', 'scheduled_confirmed']],
        'on_job' => ['label' => 'On job', 'hint' => 'Accepted through arrival at drop-off', 'statuses' => ['accepted', 'assigned', 'on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff']],
        'payment' => ['label' => 'Payment & verification', 'hint' => 'Verification or payment pending', 'statuses' => ['waiting_verification', 'payment_pending', 'payment_submitted']],
        'returned' => ['label' => 'Returned', 'hint' => 'Needs reassignment', 'statuses' => ['returned']],
        'completed' => ['label' => 'Completed', 'hint' => null, 'statuses' => ['completed']],
        'cancelled' => ['label' => 'Cancelled', 'hint' => null, 'statuses' => ['cancelled'], 'divider' => true],
        'rejected' => ['label' => 'Rejected', 'hint' => null, 'statuses' => ['rejected']],
    ];

    public const DATE_PRESETS = [
        'all' => 'All dates',
        'today' => 'Today',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        'custom' => 'Custom range',
    ];

    private const LABELS = [
        'requested' => 'Requested',
        'reviewed' => 'Under review',
        'quoted' => 'Quoted',
        'quotation_sent' => 'Quotation sent',
        'confirmed' => 'Confirmed',
        'scheduled' => 'Scheduled',
        'scheduled_confirmed' => 'Scheduled, confirmed',
        'accepted' => 'Accepted',
        'assigned' => 'Unit assigned',
        'on_the_way' => 'On the way',
        'arrived_pickup' => 'Arrived at pickup',
        'in_progress' => 'Towing in progress',
        'loading_vehicle' => 'Loading vehicle',
        'on_job' => 'On job',
        'arrived_dropoff' => 'Arrived at drop-off',
        'waiting_verification' => 'Awaiting verification',
        'payment_pending' => 'Payment pending',
        'payment_submitted' => 'Payment submitted',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'rejected' => 'Rejected',
        'not_responding' => 'No response',
        'returned' => 'Returned',
        'delayed' => 'Delayed',
    ];

    private const TONES = [
        'teal' => ['requested', 'reviewed'],
        'slate' => ['quoted', 'quotation_sent'],
        'indigo' => ['confirmed', 'scheduled', 'scheduled_confirmed'],
        'blue' => ['accepted', 'assigned', 'on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'on_job', 'arrived_dropoff'],
        'violet' => ['waiting_verification', 'payment_pending', 'payment_submitted'],
        'green' => ['completed'],
        'red' => ['cancelled', 'rejected', 'not_responding'],
        'orange' => ['returned', 'delayed'],
    ];

    /** Statuses that only exist after a price has been confirmed. */
    private const PRICED_STATUSES = [
        'accepted', 'assigned', 'on_the_way', 'arrived_pickup', 'in_progress', 'loading_vehicle', 'on_job',
        'arrived_dropoff', 'waiting_verification', 'payment_pending', 'payment_submitted', 'completed', 'returned', 'delayed',
    ];

    private const NOT_CHARGED_STATUSES = ['cancelled', 'rejected'];

    private const PAYMENT_METHODS = ['gcash' => 'GCash', 'bank_transfer' => 'Bank transfer', 'cash' => 'Cash'];

    /** @var array<string, array|null> */
    private array $groupCache = [];

    public function __construct(private DocumentGenerationService $documents)
    {
    }

    public function statusLabel(?string $status): string
    {
        return self::LABELS[$status] ?? str((string) $status)->replace('_', ' ')->ucfirst()->toString();
    }

    public function statusTone(?string $status): string
    {
        foreach (self::TONES as $tone => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $tone;
            }
        }

        return 'neutral';
    }

    public function formatPhone(?string $phone): ?string
    {
        if (! filled($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        // Stored as +639XXXXXXXXX; shown in the local 0917 555 0142 form.
        if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
            $digits = '0' . substr($digits, 2);
        }

        return strlen($digits) === 11 && str_starts_with($digits, '09')
            ? substr($digits, 0, 4) . ' ' . substr($digits, 4, 3) . ' ' . substr($digits, 7)
            : $phone;
    }

    public function peso(float $amount): string
    {
        return '₱' . number_format($amount, 2);
    }

    public function scheduledAt(Booking $booking): ?CarbonInterface
    {
        if ($booking->scheduled_for) {
            return $booking->scheduled_for;
        }

        if ($booking->scheduled_date) {
            return $booking->scheduled_date->copy()->setTimeFromTimeString((string) ($booking->scheduled_time ?: '00:00'));
        }

        return null;
    }

    /** Service line for the list: "Book Now / Booked …" or "Scheduled / For …". */
    public function serviceSummary(Booking $booking): array
    {
        if ($booking->service_type === 'schedule') {
            $at = $this->scheduledAt($booking);

            return ['label' => 'Scheduled', 'note' => $at ? 'For ' . $at->format('M j, g:i A') : null];
        }

        return ['label' => 'Book Now', 'note' => $booking->created_at ? 'Booked ' . $booking->created_at->format('M j, g:i A') : null];
    }

    /**
     * Amount cell for the bookings list: a confirmed or quoted figure and what
     * it stands for, or null (shown as a dash) while only an estimate exists.
     *
     * @return array{amount: ?float, note: ?string}
     */
    public function listAmount(Booking $booking): array
    {
        if (in_array($booking->status, self::NOT_CHARGED_STATUSES, true)) {
            return ['amount' => null, 'note' => null];
        }

        $group = $this->group($booking);

        if ($group && $group['normalized']) {
            $amount = $this->memberAmount($booking, $group);
            $state = $group['state'];

            return match ($state) {
                'quoted' => ['amount' => $amount, 'note' => $amount !== null ? 'Quotation sent' : null],
                'accepted' => ['amount' => $amount, 'note' => $this->paymentNote($booking) ?? 'Quotation accepted'],
                default => ['amount' => null, 'note' => null],
            };
        }

        if ($group) {
            return ['amount' => null, 'note' => null];
        }

        $quotation = $this->soloQuotation($booking);
        $state = $this->soloState($booking, $quotation);

        return match ($state) {
            'quoted' => ['amount' => (float) $quotation->estimated_price, 'note' => 'Quotation sent'],
            'closed' => ['amount' => (float) $quotation->estimated_price, 'note' => 'Quotation ' . $this->closedWord($quotation->status)],
            'final' => [
                'amount' => (float) $booking->final_total,
                'note' => $this->paymentNote($booking) ?? ($quotation?->status === 'accepted' ? 'Quotation accepted' : null),
            ],
            default => ['amount' => null, 'note' => null],
        };
    }

    /** JSON payload for the Booking Details modal. */
    public function details(Booking $booking): array
    {
        $booking->loadMissing(['customer', 'truckType', 'vehicleType', 'unit', 'assignedTeamLeader', 'receipt']);
        $group = $this->group($booking);

        $pickupNote = filled($booking->pickup_notes) && trim((string) $booking->pickup_notes) !== trim((string) $booking->notes)
            ? trim((string) $booking->pickup_notes)
            : null;

        $payload = [
            'code' => $booking->job_code,
            'status' => ['label' => $this->statusLabel($booking->status), 'tone' => $this->statusTone($booking->status)],
            'created' => $booking->created_at?->format('M j, Y \a\t g:i A'),
            'customer' => [
                'name' => $booking->customer->full_name ?? null,
                'phone' => $this->formatPhone($booking->customer->phone ?? null),
                'email' => $booking->customer->email ?? null,
            ],
            'service' => [
                'type' => $booking->service_type === 'schedule' ? 'Scheduled' : 'Book Now',
                'scheduled_for' => $booking->service_type === 'schedule' ? $this->scheduledAt($booking)?->format('M j, Y, g:i A') : null,
                'truck_type' => $booking->truckType->name ?? null,
                'vehicle_type' => $booking->vehicleType->name ?? null,
            ],
            'assignment' => $booking->unit ? [
                'unit' => $booking->unit->name,
                'plate' => $booking->unit->plate_number,
                'team_leader' => $booking->assignedTeamLeader?->full_name ?: $booking->assignedTeamLeader?->name,
                'assigned_at' => $booking->assigned_at?->format('M j, Y, g:i A'),
            ] : null,
            'route' => [
                'pickup' => $booking->pickup_address,
                'pickup_note' => $pickupNote,
                'dropoff' => $booking->dropoff_address,
                'distance' => $booking->distance_km !== null ? rtrim(rtrim(number_format((float) $booking->distance_km, 1), '0'), '.') . ' km' : null,
            ],
            'notes' => filled($booking->notes) ? trim((string) $booking->notes) : null,
        ];

        if ($group && $group['normalized']) {
            $payload['pricing'] = $this->groupPricing($booking, $group);
            $payload['vehicles'] = $this->groupVehicles($booking, $group);
        } elseif ($group) {
            $payload['pricing'] = $this->legacyPricing($booking);
            $payload['related'] = $group['members']
                ->reject(fn (Booking $m) => $m->id === $booking->id)
                ->map(fn (Booking $m) => [
                    'code' => $m->job_code,
                    'vehicle' => $m->vehicleType->name ?? ($m->truckType->name ?? null),
                    'vehicle_note' => $m->vehicleType ? ($m->truckType->name ?? null) : null,
                    'status' => ['label' => $this->statusLabel($m->status), 'tone' => $this->statusTone($m->status)],
                ])
                ->values()
                ->all();
        } else {
            $payload['pricing'] = $this->soloPricing($booking);
        }

        return $payload;
    }

    // ------------------------------------------------------------------
    // Group resolution
    // ------------------------------------------------------------------

    /**
     * The booking's request group, or null when it stands alone. Members are
     * the same-trip bookings (group_code + pickup + drop-off) of the same
     * customer; "normalized" groups carry per-vehicle lines with booking ids.
     */
    public function group(Booking $booking): ?array
    {
        if (! $booking->group_code) {
            return null;
        }

        $key = implode('|', [$booking->group_code, $booking->customer_id, $booking->pickup_address, $booking->dropoff_address]);

        if (array_key_exists($key, $this->groupCache)) {
            return $this->groupCache[$key];
        }

        $members = Invoice::sameTripMembers($booking)
            ->where('customer_id', $booking->customer_id)
            ->with(['truckType', 'vehicleType', 'receipt'])
            ->orderBy('id')
            ->get();

        if ($members->count() <= 1) {
            return $this->groupCache[$key] = null;
        }

        $anchor = $members->first();
        $quotation = Quotation::where('source_booking_id', $anchor->id)->current()->latest('id')->first()
            ?? $members->pluck('quotation_id')->filter()->map(fn ($id) => Quotation::find($id))->filter()->first();

        $lines = collect($quotation?->extra_vehicles ?? []);
        $normalized = $lines->contains(fn ($line) => ! empty($line['booking_id']))
            || collect($anchor->extra_vehicles ?? [])->contains(fn ($line) => is_array($line) && ! empty($line['booking_id']));

        return $this->groupCache[$key] = [
            'members' => $members,
            'anchor' => $anchor,
            'quotation' => $quotation,
            'lines' => $lines->filter(fn ($line) => ! empty($line['booking_id']))->keyBy('booking_id'),
            'normalized' => $normalized,
            'state' => $this->quotationState($quotation),
        ];
    }

    private function quotationState(?Quotation $quotation): string
    {
        return match ($quotation?->status) {
            'sent', 'negotiating', 'price_review_requested' => 'quoted',
            'accepted' => 'accepted',
            'rejected', 'expired', 'cancelled', 'disregarded' => 'closed',
            default => 'provisional',
        };
    }

    /** One vehicle's amount within a normalized group, or null when unknown. */
    private function memberAmount(Booking $member, array $group): ?float
    {
        if (in_array($member->status, self::NOT_CHARGED_STATUSES, true)) {
            return null;
        }

        return match ($group['state']) {
            'quoted' => isset($group['lines'][$member->id])
                ? (float) ($group['lines'][$member->id]['final_total'] ?? $group['lines'][$member->id]['estimated_price'] ?? 0)
                : null,
            'accepted', 'provisional' => (float) $member->final_total,
            default => null,
        };
    }

    private function groupPricing(Booking $booking, array $group): array
    {
        /** @var Collection $members */
        $members = $group['members'];
        $quotation = $group['quotation'];
        $state = $group['state'];
        $charged = $members->reject(fn (Booking $m) => in_array($m->status, self::NOT_CHARGED_STATUSES, true));
        $count = $charged->count();
        $fee = (float) ($quotation->additional_fee ?? 0);
        $discount = (float) ($quotation->discount ?? 0);

        $pricing = [
            'kind' => 'group',
            'state' => $state,
            'quotation' => $this->quotationLine($quotation),
            'payment' => $this->paymentLine($booking),
            'receipt' => $this->receiptFor($members),
        ];

        if ($state === 'closed') {
            return $pricing + ['unavailable' => 'No accepted quote'];
        }

        $subtotal = round($charged->sum(fn (Booking $m) => $this->memberAmount($m, $group) ?? 0), 2);

        if ($state === 'provisional') {
            return $pricing + [
                'rows' => [],
                'total' => ['label' => 'Estimated group total', 'amount' => $this->peso($subtotal), 'note' => $count . ' ' . str('vehicle')->plural($count)],
            ];
        }

        if ($state === 'quoted') {
            $total = (float) $quotation->estimated_price;
            $reliable = abs(round($subtotal + $fee - $discount, 2) - $total) < 0.01;

            return $pricing + [
                'rows' => $reliable ? $this->adjustmentRows($subtotal, $count . ' ' . str('vehicle')->plural($count), $fee, $discount) : [],
                'total' => ['label' => 'Quoted total', 'amount' => $this->peso($total), 'note' => null],
            ];
        }

        // Accepted: the issued invoice is authoritative once it exists.
        $invoice = $group['anchor']->currentInvoice()->first();
        $computed = max(round($subtotal + $fee - $discount, 2), 0);
        $total = $invoice ? (float) $invoice->total : $computed;
        $reliable = ! $invoice || abs($computed - $total) < 0.01;

        return $pricing + [
            'rows' => $reliable ? $this->adjustmentRows($subtotal, $count . ' charged ' . str('vehicle')->plural($count), $fee, $discount) : [],
            'total' => [
                'label' => 'Group total',
                'amount' => $this->peso($total),
                'note' => $invoice ? 'Invoice ' . $invoice->invoice_number : null,
            ],
        ];
    }

    private function adjustmentRows(float $subtotal, string $subtotalNote, float $fee, float $discount): array
    {
        $rows = [['label' => 'Vehicle subtotal', 'amount' => $this->peso($subtotal), 'note' => $subtotalNote]];

        if ($fee > 0) {
            $rows[] = ['label' => 'Group fee', 'amount' => '+' . $this->peso($fee), 'note' => null];
        }

        if ($discount > 0) {
            $rows[] = ['label' => 'Group discount', 'amount' => '−' . $this->peso($discount), 'note' => null];
        }

        return $rows;
    }

    private function groupVehicles(Booking $booking, array $group): array
    {
        $state = $group['state'];

        return [
            'note' => match ($state) {
                'quoted' => 'Amounts from the sent quotation lines',
                'provisional' => 'Estimates before quotation',
                default => 'Same customer and route',
            },
            'amount_label' => match ($state) {
                'quoted' => 'Quoted',
                'provisional' => 'Estimate',
                default => 'Amount',
            },
            'show_amounts' => $state !== 'closed',
            'rows' => $group['members']->map(function (Booking $m) use ($booking, $group) {
                $notCharged = in_array($m->status, self::NOT_CHARGED_STATUSES, true);
                $amount = $this->memberAmount($m, $group);

                return [
                    'code' => $m->job_code,
                    'is_current' => $m->id === $booking->id,
                    'vehicle' => $m->vehicleType->name ?? ($m->truckType->name ?? null),
                    'vehicle_note' => $m->vehicleType ? ($m->truckType->name ?? null) : null,
                    'status' => ['label' => $this->statusLabel($m->status), 'tone' => $this->statusTone($m->status)],
                    'amount' => $notCharged ? 'Not Charged' : ($amount !== null ? $this->peso($amount) : '—'),
                    'not_charged' => $notCharged,
                ];
            })->values()->all(),
        ];
    }

    private function legacyPricing(Booking $booking): array
    {
        $quotation = $this->soloQuotation($booking);

        return [
            'kind' => 'legacy',
            'unavailable' => 'Not available',
            'unavailable_note' => 'This older request has no reliable per-vehicle pricing.',
            'quotation' => $this->quotationLine($quotation),
            'payment' => $this->paymentLine($booking),
            'receipt' => $this->receiptFor(collect([$booking])),
        ];
    }

    // ------------------------------------------------------------------
    // Single bookings
    // ------------------------------------------------------------------

    private function soloQuotation(Booking $booking): ?Quotation
    {
        return ($booking->quotation_id ? Quotation::find($booking->quotation_id) : null)
            ?? Quotation::where('source_booking_id', $booking->id)->current()->latest('id')->first();
    }

    private function soloState(Booking $booking, ?Quotation $quotation): string
    {
        if ($quotation?->status === 'accepted' || $booking->price_locked_at || in_array($booking->status, self::PRICED_STATUSES, true)) {
            return 'final';
        }

        return $this->quotationState($quotation);
    }

    private function soloPricing(Booking $booking): array
    {
        $quotation = $this->soloQuotation($booking);
        $state = $this->soloState($booking, $quotation);
        $fee = (float) ($booking->additional_fee ?? 0);

        $total = match (true) {
            in_array($booking->status, self::NOT_CHARGED_STATUSES, true) => ['label' => 'Total', 'amount' => 'Not Charged', 'note' => null, 'muted' => true],
            $state === 'final' => ['label' => 'Total', 'amount' => $this->peso((float) $booking->final_total), 'note' => $fee > 0 ? 'Includes ' . $this->peso($fee) . ' additional fee' : null],
            $state === 'quoted', $state === 'closed' => ['label' => 'Quoted total', 'amount' => $this->peso((float) $quotation->estimated_price), 'note' => null],
            default => ['label' => 'Estimated total', 'amount' => $this->peso((float) $booking->final_total), 'note' => 'Before quotation'],
        };

        return [
            'kind' => 'single',
            'state' => $state,
            'total' => $total,
            'quotation' => $this->quotationLine($quotation),
            'payment' => $this->paymentLine($booking),
            'receipt' => $this->receiptFor(collect([$booking])),
        ];
    }

    // ------------------------------------------------------------------
    // Shared lines
    // ------------------------------------------------------------------

    private function quotationLine(?Quotation $quotation): array
    {
        $date = fn (?CarbonInterface $at) => $at?->format('M j, Y, g:i A');

        return match ($quotation?->status) {
            'sent' => ['value' => 'Sent, awaiting customer', 'note' => $quotation->expires_at ? 'Expires ' . $date($quotation->expires_at) : null],
            'negotiating' => ['value' => 'Customer requested a new price', 'note' => null],
            'price_review_requested' => ['value' => 'Price review requested', 'note' => null],
            'accepted' => ['value' => 'Accepted', 'note' => $date($quotation->responded_at)],
            'rejected' => ['value' => 'Rejected by customer', 'note' => $date($quotation->responded_at)],
            'expired' => ['value' => 'Expired', 'note' => $date($quotation->expires_at)],
            'cancelled' => ['value' => 'Cancelled', 'note' => null],
            'disregarded' => ['value' => 'Disregarded', 'note' => null],
            default => ['value' => 'Not sent yet', 'note' => null],
        };
    }

    private function closedWord(string $status): string
    {
        return match ($status) {
            'rejected' => 'rejected',
            'expired' => 'expired',
            default => 'cancelled',
        };
    }

    /** Payment state; group members share the same payment fields. */
    private function paymentLine(Booking $booking): array
    {
        $method = self::PAYMENT_METHODS[$booking->payment_method] ?? null;
        $with = fn (?CarbonInterface $at) => trim(implode(' · ', array_filter([$method, $at?->format('M j, Y, g:i A')])));

        if ($booking->status === 'completed' && $booking->completed_at) {
            return ['value' => 'Confirmed', 'note' => $with($booking->completed_at) ?: null];
        }

        if ($booking->payment_submitted_at) {
            return ['value' => 'Submitted', 'note' => $with($booking->payment_submitted_at) ?: null];
        }

        return ['value' => 'Not submitted', 'note' => null];
    }

    private function paymentNote(Booking $booking): ?string
    {
        if ($booking->status === 'completed' && $booking->completed_at) {
            return 'Payment confirmed';
        }

        return $booking->payment_submitted_at ? 'Payment submitted' : null;
    }

    private function receiptFor(Collection $members): ?array
    {
        $receipt = $members->map(fn (Booking $m) => $m->relationLoaded('receipt') ? $m->receipt : $m->receipt()->first())
            ->filter()
            ->first() ?? Receipt::whereIn('booking_id', $members->pluck('id'))->first();

        if (! $receipt) {
            return null;
        }

        return [
            'code' => $receipt->receipt_code ?? $receipt->receipt_number,
            'url' => $this->documents->publicDocumentUrl($receipt->pdf_path),
        ];
    }
}
