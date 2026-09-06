<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffPasswordResetOtp extends Model
{
    protected $fillable = [
        'email',
        'otp_hash',
        'expires_at',
        'verified_at',
        'failed_attempts',
        'last_sent_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'failed_attempts' => 'integer',
    ];

    public function isExpired(): bool
    {
        return now()->isAfter($this->expires_at);
    }
}
