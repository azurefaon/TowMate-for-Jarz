<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use HasFactory;

    protected $table = 'units';

    public const SLOT_COLUMNS = [
        'driver_1'       => 'driver_name',
        'driver_2'       => 'driver_2_name',
        'crew_member_1'  => 'crew_member_1_name',
        'crew_member_2'  => 'crew_member_2_name',
    ];

    protected $fillable = [
        'name',
        'plate_number',
        'truck_type_id',
        'driver_id',
        'driver_name',
        'driver_2_name',
        'crew_member_1_name',
        'crew_member_2_name',
        'team_leader_id',
        'zone_id',
        'status',
        'issue_note',
        'dispatcher_status',
        'zone_confirmed',
        'dispatcher_note',
        'last_updated_by',
        'last_updated_at',
        'current_lat',
        'current_lng',
        'location_accuracy',
        'location_updated_at',
        'archived_at',
    ];

    protected $casts = [
        'location_updated_at' => 'datetime',
        'current_lat'         => 'float',
        'current_lng'         => 'float',
        'location_accuracy'   => 'float',
        'archived_at'         => 'datetime',
    ];

    public function auditLabel(): string
    {
        return 'Unit ' . ($this->name ?: $this->plate_number ?: "#{$this->getKey()}");
    }

    public function zone()
    {
        return $this->belongsTo(Zone::class);
    }

    public function truckType()
    {
        return $this->belongsTo(\App\Models\TruckType::class, 'truck_type_id');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function teamLeader()
    {
        return $this->belongsTo(User::class, 'team_leader_id');
    }

    public function crewLoansOut()
    {
        return $this->hasMany(UnitCrewLoan::class, 'from_unit_id');
    }

    public function crewLoansIn()
    {
        return $this->hasMany(UnitCrewLoan::class, 'to_unit_id');
    }

    public function activeLoanOut(string $slot): ?UnitCrewLoan
    {
        return $this->crewLoansOut()
            ->where('from_slot', $slot)
            ->whereNull('returned_at')
            ->first();
    }

    public function activeLoansIn()
    {
        return $this->crewLoansIn()
            ->whereNull('returned_at')
            ->get()
            ->keyBy('to_slot');
    }
}
