<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleCategory extends Model
{
    protected $fillable = [
        'slug',
        'name',
    ];

    public function vehicleTypes()
    {
        return $this->hasMany(VehicleType::class, 'category', 'slug');
    }

    public function auditLabel(): string
    {
        return 'Vehicle Category ' . ($this->name ?: "#{$this->getKey()}");
    }
}
