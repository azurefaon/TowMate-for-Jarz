<?php

namespace App\Console\Commands;

use App\Services\OnlinePayments\OnlinePaymentService;
use Illuminate\Console\Command;

/**
 * Closes out sandbox checkout sessions whose time is up, after first asking the provider whether a
 * payment landed that the webhook missed. Not scheduled automatically: run it by hand or schedule it
 * deliberately once the sandbox is switched on. Does nothing while the feature is disabled.
 */
class ReconcileOnlinePayments extends Command
{
    protected $signature = 'online-payments:reconcile';

    protected $description = 'Reconcile and expire PayMongo sandbox checkout sessions (no-op unless the sandbox is enabled)';

    public function handle(OnlinePaymentService $payments): int
    {
        $summary = $payments->reconcile();

        $this->info(sprintf(
            'Checked %d, paid %d, expired %d, unconfirmed %d, prepaid bookings submitted %d',
            $summary['checked'],
            $summary['paid'],
            $summary['expired'],
            $summary['unconfirmed'],
            $summary['submitted'],
        ));

        return self::SUCCESS;
    }
}
