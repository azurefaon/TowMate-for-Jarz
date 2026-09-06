<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitCrewLoan extends Model
{
    protected $fillable = [
        'from_unit_id',
        'to_unit_id',
        'from_slot',
        'to_slot',
        'person_name',
        'person_user_id',
        'borrowed_at',
        'returned_at',
        'created_by',
    ];

    protected $casts = [
        'borrowed_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function fromUnit()
    {
        return $this->belongsTo(Unit::class, 'from_unit_id');
    }

    public function toUnit()
    {
        return $this->belongsTo(Unit::class, 'to_unit_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Only set for Team Leader loans (a real, stable account) — Driver/Crew
     * loans continue to identify the person by person_name alone.
     */
    public function person()
    {
        return $this->belongsTo(User::class, 'person_user_id');
    }
}
