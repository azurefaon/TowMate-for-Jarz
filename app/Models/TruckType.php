<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TruckType extends Model
{
    protected $fillable = [
        'name',
        'class',
        'base_rate',
        'per_km_rate',
        'max_tonnage',
        'description',
        'status'
    ];

    public function units()
    {
        return $this->hasMany(\App\Models\Unit::class, 'truck_type_id');
    }

    public function bookings()
    {
        return $this->hasMany(\App\Models\Booking::class, 'truck_type_id');
    }

    public function vehicleTypes()
    {
        return $this->belongsToMany(\App\Models\VehicleType::class, 'vehicle_type_truck_type');
    }
}
