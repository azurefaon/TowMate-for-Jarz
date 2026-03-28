<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Models\Customer;
use App\Models\TruckType;
use App\Models\Unit;
use App\Models\Receipt;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'truck_type_id',
        'assigned_unit_id',

        'pickup_address',
        'pickup_lat',
        'pickup_lng',

        'dropoff_address',
        'dropoff_lat',
        'dropoff_lng',

        'distance_km',
        'base_rate',
        'per_km_rate',
        'final_total',

        'status',
        'created_by_admin_id',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function truckType()
    {
        return $this->belongsTo(TruckType::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'assigned_unit_id');
    }

    public function receipt()
    {
        return $this->hasOne(Receipt::class);
    }
}
