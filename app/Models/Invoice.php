<?php

namespace App\Models;

use App\Models\Concerns\GeneratesPublicCode;
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
