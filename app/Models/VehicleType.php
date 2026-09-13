<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleType extends Model
{
    protected $fillable = [
        'name',
        'category',
        'weight_kg',
        'required_truck_type_id',
        'description',
        'icon_path',
        'display_order',
        'status'
    ];

    protected $casts = [
        'weight_kg' => 'decimal:2',
    ];

    public function auditLabel(): string
    {
        return 'Vehicle Type ' . ($this->name ?: "#{$this->getKey()}");
    }

    public function truckTypes()
    {
        return $this->belongsToMany(TruckType::class, 'vehicle_type_truck_type');
    }

    public function requiredTruckType()
    {
        return $this->belongsTo(TruckType::class, 'required_truck_type_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function quotations()
    {
        return $this->hasMany(Quotation::class);
    }

    public function getCategoryLabelAttribute()
    {
        return match($this->category) {
            '2_wheeler' => '2-Wheeler',
            '4_wheeler' => '4-Wheeler',
            'heavy_vehicle' => 'Heavy Vehicle',
            default => 'Unknown'
        };
    }
}
