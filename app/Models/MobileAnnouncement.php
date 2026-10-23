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
     * Owner-facing display state. Mirrors the window rules in current(): the
     * enabled flag (is_active) is separate from whether customers can see it
     * right now. Both use the app timezone via now().
     *
     * @return 'active'|'scheduled'|'expired'|'inactive'
     */
    public function displayStatus(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }

        if ($this->end_at && $this->end_at->lt(now())) {
            return 'expired';
        }

        if ($this->start_at && $this->start_at->gt(now())) {
            return 'scheduled';
        }

        return 'active';
    }

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
