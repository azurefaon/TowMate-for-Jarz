<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LandingSetting extends Model
{
    protected $fillable = [
        'hero_image',
        'about_image',
        'portfolio_main',
        'portfolio_1',
        'portfolio_2',
        'portfolio_3',
        'contact_phone',
        'contact_email',
        'contact_location'
    ];
}
