<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LockInactiveCustomers extends Command
{
    protected $signature = 'towmate:lock-inactive-customers';

    protected $description = 'Automatically lock customer accounts that have had no login for the configured inactivity threshold';

    public function handle(): int
    {
        $thresholdDays = max((int) SystemSetting::getValue('customer_inactivity_lock_days', 90), 1);
        $cutoff = now()->subDays($thresholdDays);

        $candidates = User::whereHas('role', fn($q) => $q->where('name', 'Customer'))
            ->whereNull('archived_at')
            ->whereNull('pending_delete_at')
            ->where('status', 'active')
            ->where(DB::raw('COALESCE(last_login_at, created_at)'), '<=', $cutoff)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No inactive customer accounts to lock.');
            return self::SUCCESS;
        }

        foreach ($candidates as $user) {
            $user->update(['status' => 'locked']);
            $user->tokens()->delete();

            AuditLog::create([
                'user_id' => null,
                'action' => 'customer_auto_locked',
                'entity_type' => 'User',
                'entity_id' => $user->id,
                'reference' => $user->name,
                'description' => "Locked after {$thresholdDays}+ days of inactivity.",
            ]);
        }

        $this->info("Locked {$candidates->count()} inactive customer account(s).");

        return self::SUCCESS;
    }
}
