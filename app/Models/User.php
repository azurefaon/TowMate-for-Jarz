<?php

namespace App\Models;

use App\Models\Concerns\GeneratesPublicCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, GeneratesPublicCode;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_code',
        'name',
        'first_name',
        'middle_name',
        'last_name',
        'username',
        'email',
        'phone',
        'password',
        'role_id',
        'duty_class',
        'password_reset_otp',
        'password_reset_otp_expires_at',
        'password_reset_token',
        'status',
        'password_request_status',
        'password_requested_at',
        'password_request_note',
        'password_request_resolved_at',
        'archived_at',
        'email_verified_at',
        'must_change_password',
        'last_ping_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (blank($user->user_code)) {
                $user->user_code = static::nextPublicCode('user_code');
            }
        });

        static::saving(function (User $user) {
            $user->name = build_full_name(
                $user->first_name,
                $user->middle_name,
                $user->last_name,
            ) ?: $user->name;

            $user->email = strtolower(trim((string) $user->email));
        });
    }

    public function getFullNameAttribute(): string
    {
        return build_full_name($this->first_name, $this->middle_name, $this->last_name) ?: (string) $this->name;
    }

    public function scopeVisibleToOperations($query)
    {
        return $query->whereNull('archived_at');
    }

    public function role()
    {
        return $this->belongsTo(\App\Models\Role::class, 'role_id');
    }

    public function customer()
    {
        return $this->hasOne(\App\Models\Customer::class);
    }

    public function unit()
    {
        return $this->hasOne(\App\Models\Unit::class, 'team_leader_id');
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'archived_at' => 'datetime',
            'password_requested_at' => 'datetime',
            'password_request_resolved_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'last_ping_at' => 'datetime',
        ];
    }
}
