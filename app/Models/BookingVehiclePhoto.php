<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingVehiclePhoto extends Model
{
    protected $fillable = [
        'booking_id',
        'vehicle_slot',
        'path',
    ];

    protected $casts = [
        'vehicle_slot' => 'integer',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function getUrlAttribute(): ?string
    {
        return protected_file_url($this->path);
    }
}
