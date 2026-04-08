<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Booking;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'customer_code',
        'full_name',
        'age',
        'phone',
        'email',
        'is_pwd',
        'is_senior',
    ];

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
