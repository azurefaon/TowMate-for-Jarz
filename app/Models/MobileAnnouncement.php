<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileAnnouncement extends Model
{
    protected $fillable = [
        'title',
        'message',
        'is_active',
        'start_at',
        'end_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    /**
     * The single announcement customers should currently see, or null.
     * Most-recently-updated wins if more than one row qualifies.
     */
    public static function current(): ?self
    {
        return static::query()
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('start_at')->orWhere('start_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('end_at')->orWhere('end_at', '>=', now());
            })
            ->latest('updated_at')
            ->first();
    }
}
