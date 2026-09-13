<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Personnel extends Model
{
    protected $table = 'personnel';

    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'role',
        'home_unit_id',
        'personnel_status',
    ];

    public function getFullNameAttribute(): string
    {
        return build_full_name($this->first_name, $this->middle_name, $this->last_name) ?: trim("{$this->first_name} {$this->last_name}");
    }

    public function auditLabel(): string
    {
        return 'Personnel ' . ($this->full_name ?: "#{$this->getKey()}");
    }

    public function homeUnit()
    {
        return $this->belongsTo(Unit::class, 'home_unit_id');
    }

    public static function ambiguousLegacyRosterNames(): array
    {
        $columns = [
            'driver_name' => 'driver',
            'driver_2_name' => 'driver',
            'crew_member_1_name' => 'crew',
            'crew_member_2_name' => 'crew',
        ];

        $seen = [];

        foreach ($columns as $column => $role) {
            Unit::whereNotNull($column)->get(['id', $column])->each(function ($unit) use (&$seen, $column, $role) {
                $name = trim((string) $unit->{$column});

                if ($name === '') {
                    return;
                }

                $key = $role . '|' . mb_strtolower($name);
                $seen[$key][] = $unit->id;
            });
        }

        return collect($seen)
            ->filter(fn (array $unitIds) => count(array_unique($unitIds)) > 1)
            ->keys()
            ->all();
    }
}
