<?php

namespace App\Models;

use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'category',
        'entity_type',
        'entity_id',
        'reference',
        'description',
        'old_value',
        'new_value',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (AuditLog $log) {
            if (blank($log->category) && filled($log->action)) {
                $log->category = AuditLogService::categoryForAction((string) $log->action);
            }

            $request = request();

            if (blank($log->ip_address) && $request) {
                $log->ip_address = $request->ip();
            }

            if (blank($log->user_agent) && $request) {
                $log->user_agent = substr((string) $request->userAgent(), 0, 255);
            }
        });

        static::created(function (AuditLog $log) {
            AuditLogService::rememberLoggedEntity($log->entity_type, $log->entity_id, $log->id);
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
