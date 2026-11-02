<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One received provider event. The unique provider_event_id is what makes replays harmless. */
class PaymentWebhookEvent extends Model
{
    protected $fillable = [
        'provider', 'provider_event_id', 'event_type', 'livemode', 'payload_hash',
        'payment_transaction_id', 'outcome', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'livemode' => 'boolean',
            'received_at' => 'datetime',
        ];
    }
}
