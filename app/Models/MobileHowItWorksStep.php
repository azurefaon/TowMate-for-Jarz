<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileHowItWorksStep extends Model
{
    protected $table = 'mobile_how_it_works_steps';

    protected $fillable = [
        'step_title',
        'step_description',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];
}
