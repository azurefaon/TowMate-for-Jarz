<?php

namespace App\Models;

use App\Models\Concerns\GeneratesPublicCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use GeneratesPublicCode;

    protected $fillable = [
        'invoice_number',
        'booking_id',
        'quotation_id',
        'previous_invoice_id',
        'original_invoice_id',
        'subtotal',
        'additional_fee',
        'discount',
        'total',
        'status',
        'is_current',
        'voided_at',
        'void_reason',
        'pdf_path',
        'email_sent',
        'created_by',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'additional_fee' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'is_current' => 'boolean',
        'email_sent' => 'boolean',
        'voided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice) {
            if (blank($invoice->invoice_number)) {
                $invoice->invoice_number = 'IV-' . static::nextPublicCode('invoice_number');
            }
        });
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function previousInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'previous_invoice_id');
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'original_invoice_id');
    }

    /*
     * ------------------------------------------------------------------
     * Receipt lock: the ONE authoritative definition of "has a receipt already
     * finalized this transaction, so its invoice can no longer be corrected?".
     * Used by InvoiceController (Void & Replace enforcement) and by the
     * dispatcher booking-detail bundle (UI eligibility), so they cannot drift.
     *
     * A "transaction" is exactly:
     *  - a normalized group (quotation.extra_vehicles carries booking ids): the
     *    same-trip members of the invoice's booking, i.e. group_code + pickup +
     *    dropoff (the same set Void & Replace locks and recomputes); or
     *  - anything else (solo, and legacy non-normalized groups where each booking
     *    has its own invoice/receipt): just the invoice's own booking.
     * Merely sharing a group_code is NOT membership.
     * ------------------------------------------------------------------
     */

    /** True for a normalized group booking (quotation.extra_vehicles carries booking ids). */
    public static function isNormalizedGroupTransaction(Booking $booking): bool
    {
        if (! $booking->group_code || ! $booking->quotation_id) {
            return false;
        }

        $quotation = Quotation::find($booking->quotation_id);
        if (! $quotation) {
            return false;
        }

        return collect($quotation->extra_vehicles ?? [])->contains(fn ($ev) => ! empty($ev['booking_id']));
    }

    /** The legitimate same-trip members of a normalized group (group_code + pickup + dropoff). */
    public static function sameTripMembers(Booking $anchor): Builder
    {
        return Booking::where('group_code', $anchor->group_code)
            ->where('pickup_address', $anchor->pickup_address)
            ->where('dropoff_address', $anchor->dropoff_address);
    }

    /** Booking ids of the transaction this invoice belongs to (see the block comment above). */
    public function transactionBookingIds(): array
    {
        $booking = $this->booking;

        if (! $booking) {
            return [];
        }

        if (! static::isNormalizedGroupTransaction($booking)) {
            return [$booking->id];
        }

        return static::sameTripMembers($booking)->pluck('id')->all();
    }

    /**
     * True when a canonical Receipt already exists for this transaction. Follows
     * the Receipt relationships, never the booking status:
     *  - a Receipt owned by any booking of the transaction, or
     *  - a Receipt attached to any invoice of those bookings (historical/voided
     *    invoices count; no is_current restriction; includes this invoice).
     * Read-only. Pass $bookingIds only when the caller already holds the locked
     * member rows of this same transaction (Void & Replace does).
     */
    public function hasIssuedReceipt(?array $bookingIds = null): bool
    {
        $bookingIds ??= $this->transactionBookingIds();

        $invoiceIds = static::whereIn('booking_id', $bookingIds)
            ->pluck('id')
            ->push($this->id)
            ->unique()
            ->all();

        return Receipt::whereIn('booking_id', $bookingIds)
            ->orWhereIn('invoice_id', $invoiceIds)
            ->exists();
    }

    /**
     * Voiding never edits or reuses a number: the current row is closed out
     * and a new invoice is opened that carries the billing info forward
     * (so only the mistake needs correcting) and points back at the one it replaced.
     */
    public function voidAndReplace(string $reason, array $corrections = [], ?int $voidedBy = null): Invoice
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($reason, $corrections, $voidedBy) {
            $this->update([
                'status' => 'voided',
                'voided_at' => now(),
                'void_reason' => $reason,
                'is_current' => false,
            ]);

            // Refresh first: attributes never set explicitly (left to DB column
            // defaults, e.g. additional_fee/discount) would otherwise copy forward
            // as null and violate not-null constraints instead of the real value.
            $this->refresh();

            $attributes = $this->only(['booking_id', 'quotation_id', 'subtotal', 'additional_fee', 'discount', 'total']);

            return static::create(array_merge($attributes, $corrections, [
                'previous_invoice_id' => $this->id,
                'original_invoice_id' => $this->original_invoice_id ?? $this->id,
                'status' => 'issued',
                'is_current' => true,
                'created_by' => $voidedBy,
            ]));
        });
    }
}
