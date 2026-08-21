<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\UserPurgeService;
use Illuminate\Console\Command;

class PurgeDeletedUsers extends Command
{
    protected $signature = 'towmate:purge-deleted-users';

    protected $description = 'Permanently delete (or anonymize, if blocked by receipt/booking history) users past their Deleted-list retention period';

    public function handle(UserPurgeService $purgeService): int
    {
        $retentionDays = max((int) SystemSetting::getValue('deleted_retention_days', 30), 1);
        $cutoff = now()->subDays($retentionDays);

        $candidates = User::whereNotNull('pending_delete_at')
            ->where('pending_delete_at', '<=', $cutoff)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No users past their Deleted-list retention period.');
            return self::SUCCESS;
        }

        $deletedCount = 0;
        $anonymizedCount = 0;

        foreach ($candidates as $user) {
            $purgeService->purge($user, automatic: true) === 'deleted'
                ? $deletedCount++
                : $anonymizedCount++;
        }

        $this->info("Purged {$deletedCount} user(s), anonymized {$anonymizedCount} user(s) blocked by receipt/booking history.");

        return self::SUCCESS;
    }
}
