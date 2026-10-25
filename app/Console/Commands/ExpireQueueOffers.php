<?php

namespace App\Console\Commands;

use App\Models\BookingQueueOffer;
use App\Services\NextTaskQueueService;
use Illuminate\Console\Command;

class ExpireQueueOffers extends Command
{
    protected $signature = 'queue:expire-offers';
    protected $description = 'Expire overdue next-task offers and cancel those whose booking or team changed';

    public function handle(NextTaskQueueService $queue): int
    {
        $before = BookingQueueOffer::active()->count();
        $queue->sweep();

        $this->info('Released ' . ($before - BookingQueueOffer::active()->count()) . ' queued offer(s)');

        return Command::SUCCESS;
    }
}
