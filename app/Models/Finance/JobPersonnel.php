<?php

namespace App\Models\Finance;

use App\Models\Booking;
use App\Models\Personnel;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who worked a job, frozen at the time. Corrections supersede the row instead of editing it. */
class JobPersonnel extends Model
{
    public const SLOTS = ['team_leader', 'driver_1', 'driver_2', 'crew_member_1', 'crew_member_2'];
    public const SOURCES = ['completion_capture', 'backfill', 'manual'];

    protected $table = 'finance_job_personnel';

    protected $fillable = [
        'booking_id', 'unit_id', 'slot', 'personnel_id', 'user_id', 'name_snapshot', 'commission_role',
        'borrowed_from_unit_id', 'source', 'needs_review', 'captured_by', 'captured_at',
    ];

    protected $casts = [
        'needs_review' => 'boolean',
        'captured_at' => 'datetime',
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

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function scopeCurrent($query)
    {
        return $query->whereNull('superseded_at');
    }
}
