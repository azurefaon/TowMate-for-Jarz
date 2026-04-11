<?php

namespace App\Models;

use App\Models\Concerns\GeneratesPublicCode;
use Illuminate\Database\Eloquent\Model;

class Receipt extends Model
{
    use GeneratesPublicCode;

    protected $fillable = [
        'receipt_code',
        'booking_id',
        'generated_by',
        'receipt_number',
        'pdf_path',
        'email_sent',
    ];

    protected static function booted(): void
    {
        static::creating(function (Receipt $receipt) {
            if (blank($receipt->receipt_code)) {
                $receipt->receipt_code = static::nextPublicCode('receipt_code');
            }
        });
    }

    protected $casts = [
        'email_sent' => 'boolean',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
