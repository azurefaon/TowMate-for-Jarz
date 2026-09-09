<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileCoverageArea extends Model
{
    protected $fillable = [
        'name',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];
}
