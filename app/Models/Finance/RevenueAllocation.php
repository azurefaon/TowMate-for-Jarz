<?php

namespace App\Models\Finance;

use App\Casts\MoneyCast;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Collected revenue attributed to a unit for one booking. It references the existing booking, invoice and
 * payment_transactions rows and never duplicates the customer payment itself.
 */
class RevenueAllocation extends Model
{
    protected $table = 'finance_revenue_allocations';

    protected $fillable = [
        'booking_id', 'unit_id', 'gross_amount', 'vat_amount', 'net_amount', 'allocation_method',
        'invoice_id', 'payment_transaction_id', 'collected_at', 'computed_by',
    ];

    protected $casts = [
        'gross_amount' => MoneyCast::class,
        'vat_amount' => MoneyCast::class,
        'net_amount' => MoneyCast::class,
        'collected_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }

    public function scopeCurrent($query)
    {
        return $query->whereNull('superseded_at');
    }
}
