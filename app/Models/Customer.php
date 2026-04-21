<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'customer_code',
        'full_name',
        'first_name',
        'middle_name',
        'last_name',
        'age',
        'phone',
        'email',
        'customer_type',
        'risk_level',
        'risk_reason',
        'blacklisted_at',
        'is_pwd',
        'is_senior',
    ];

    protected $casts = [
        'is_pwd' => 'boolean',
        'is_senior' => 'boolean',
        'blacklisted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Customer $customer) {
            if (! Schema::hasTable($customer->getTable())) {
                return;
            }

            $columns = array_flip(Schema::getColumnListing($customer->getTable()));
            $fullName = build_full_name(
                $customer->first_name,
                $customer->middle_name,
                $customer->last_name,
            ) ?: $customer->full_name;

            if (isset($columns['full_name'])) {
                $customer->full_name = $fullName;
            }

            if (isset($columns['phone'])) {
                $customer->phone = normalize_ph_phone($customer->phone) ?? $customer->phone;
            }

            if (isset($columns['email'])) {
                $customer->email = filled($customer->email) ? strtolower(trim((string) $customer->email)) : null;
            }

            $customerType = $customer->customer_type;

            if (! in_array($customerType, ['regular', 'pwd', 'senior'], true)) {
                $customerType = $customer->is_pwd ? 'pwd' : ($customer->is_senior ? 'senior' : 'regular');
            }

            if (isset($columns['customer_type'])) {
                $customer->customer_type = $customerType;
            }

            if (isset($columns['is_pwd'])) {
                $customer->is_pwd = $customerType === 'pwd';
            }

            if (isset($columns['is_senior'])) {
                $customer->is_senior = $customerType === 'senior';
            }
        });
    }

    public function getFullNameAttribute($value): string
    {
        return build_full_name($this->first_name, $this->middle_name, $this->last_name) ?: (string) $value;
    }

    public function getIsBlacklistedAttribute(): bool
    {
        return strtolower((string) ($this->risk_level ?? '')) === 'blacklisted';
    }

    public function getRiskStatusLabelAttribute(): string
    {
        return match (strtolower((string) ($this->risk_level ?? ''))) {
            'watchlist' => 'Watchlist',
            'blacklisted' => 'Blacklisted',
            default => 'Clear',
        };
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
