<?php

namespace App\Models\Finance;

use App\Models\Booking;
use App\Models\Personnel;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who actually worked a job, for which towing unit and in which role, frozen at the time. Corrections supersede the
 * row (with a reason) instead of editing it.
 */
class JobPersonnel extends Model
{
    public const SLOTS = ['team_leader', 'driver_1', 'driver_2', 'crew_member_1', 'crew_member_2', 'extra_1', 'extra_2', 'extra_3'];
    public const SOURCES = ['completion_capture', 'backfill', 'manual', 'assignment_capture'];

    public const SOURCE_LABELS = [
        'manual' => 'Recorded by the Owner',
        'assignment_capture' => 'Assigned by Dispatch',
        'completion_capture' => 'Captured at completion',
        'backfill' => 'Backfilled',
    ];

    public const ROLE_LABELS = [
        'team_leader' => 'Team Leader',
        'driver' => 'Driver',
        'rider' => 'Rider / Substitute',
        'crew_mate' => 'Crew Mate',
    ];

    /** Storage positions tried in order for each performed role. The position never decides the role. */
    public const ROLE_SLOTS = [
        'team_leader' => ['team_leader', 'extra_1', 'extra_2', 'extra_3'],
        'driver' => ['driver_1', 'driver_2', 'extra_1', 'extra_2', 'extra_3'],
        'rider' => ['driver_2', 'extra_1', 'extra_2', 'extra_3'],
        'crew_mate' => ['crew_member_1', 'crew_member_2', 'extra_1', 'extra_2', 'extra_3'],
    ];

    protected $table = 'finance_job_personnel';

    protected $fillable = [
        'booking_id', 'unit_id', 'slot', 'personnel_id', 'user_id', 'name_snapshot', 'commission_role',
        'borrowed_from_unit_id', 'is_substitute', 'notes', 'change_reason', 'source', 'needs_review',
        'captured_by', 'captured_at', 'confirmed_by', 'confirmed_at',
    ];

    protected $casts = [
        'needs_review' => 'boolean',
        'is_substitute' => 'boolean',
        'captured_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function borrowedFrom(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'borrowed_from_unit_id');
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function capturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    public function supersededByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'superseded_by_user_id');
    }

    public function sourceLabel(): string
    {
        return self::SOURCE_LABELS[$this->source] ?? $this->source;
    }

    /** Assigned by Dispatch but not yet confirmed as having actually worked the job. */
    public function isPending(): bool
    {
        return (bool) $this->needs_review;
    }

    public function roleLabel(): string
    {
        return self::ROLE_LABELS[$this->commission_role] ?? 'Role not set';
    }

    public function scopeCurrent($query)
    {
        return $query->whereNull('superseded_at');
    }
}
