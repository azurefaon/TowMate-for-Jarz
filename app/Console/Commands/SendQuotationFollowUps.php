<?php

namespace App\Console\Commands;

use App\Services\QuotationService;
use Illuminate\Console\Command;

class SendQuotationFollowUps extends Command
{
    protected $signature = 'quotations:followup';
    protected $description = 'Send follow-up reminders for quotations after 5 days with no response';

    public function handle(QuotationService $quotationService): int
    {
        $sent = $quotationService->sendFollowUpReminders();
        
        $this->info("Sent {$sent} follow-up reminder(s)");
        
        return Command::SUCCESS;
    }
}
