<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Zone extends Model
{
    protected $fillable = [
        'name',
        'description',
        'center_lat',
        'center_lng',
        'radius_km',
    ];

    protected $casts = [
        'center_lat' => 'float',
        'center_lng' => 'float',
        'radius_km' => 'float',
    ];

    public function units()
    {
        return $this->hasMany(Unit::class);
    }
}
