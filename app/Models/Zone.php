<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Zone extends Model
{
    protected $fillable = [
        'name',
        'description',
    ];

    public function units()
    {
        return $this->hasMany(Unit::class);
    }
}
