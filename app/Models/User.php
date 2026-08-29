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
        'driver_first_name',
        'driver_middle_name',
        'driver_last_name',
        'crew_member_1_name',
        'crew_member_2_name',
        'password_reset_otp',
        'password_reset_otp_expires_at',
        'password_reset_token',
        'status',
        'password_request_status',
        'password_requested_at',
        'password_request_note',
        'password_request_resolved_at',
        'archived_at',
        'archived_reason',
        'pending_delete_at',
        'pending_delete_reason',
        'anonymized_at',
        'email_verified_at',
        'must_change_password',
        'last_ping_at',
        'last_login_at',
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

    public function auditLabel(): string
    {
        return 'User ' . ($this->full_name ?: "#{$this->getKey()}");
    }

    public function scopeVisibleToOperations($query)
    {
        // Anonymized accounts are a permanent, irreversible deletion (kept only
        // because receipt/booking history references the row) — they must never
        // appear in any live operational UI (dispatch, monitoring, assignment
        // pickers, login), only in historical records that already store the
        // name/reference as plain text rather than a live relation.
        return $query->whereNull('archived_at')->whereNull('anonymized_at');
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
            'pending_delete_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'password_requested_at' => 'datetime',
            'password_request_resolved_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'last_ping_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }
}
