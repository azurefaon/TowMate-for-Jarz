<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceAdjustment extends Model
{
    protected $fillable = [
        'quotation_number',
        'type',
        'amount',
        'reason',
        'status',
        'created_by',
        'reverted_at',
        'reverted_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'reverted_at' => 'datetime',
    ];

    public function scopeForQuotation($query, string $quotationNumber)
    {
        return $query->where('quotation_number', $quotationNumber);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reverted_by');
    }
}
