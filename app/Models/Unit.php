<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use HasFactory;

    protected $table = 'units';

    protected $fillable = [
        'name',
        'plate_number',
        'truck_type_id',
        'driver_id',
        'team_leader_id',
        'zone_id',
        'status',
        'issue_note',
        'dispatcher_status',
        'zone_confirmed',
        'dispatcher_note',
        'last_updated_by',
        'last_updated_at',
    ];
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
}
