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

    public function auditLabel(): string
    {
        return 'Truck Type ' . ($this->name ?: "#{$this->getKey()}");
    }

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

    public function isCompatibleWithWeight(?float $weightKg): bool
    {
        if ($weightKg === null || $this->class === null) {
            return true;
        }

        return match ($this->class) {
            'light'  => $weightKg <= 4500,
            'medium' => $weightKg > 4500 && $weightKg <= 7500,
            'heavy'  => $weightKg > 7500,
            default  => true,
        };
    }
}
