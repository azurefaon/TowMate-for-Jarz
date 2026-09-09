<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileService extends Model
{
    protected $fillable = [
        'title',
        'description',
        'category',
        'availability_note',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];
}
