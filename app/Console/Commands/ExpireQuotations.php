<?php

namespace App\Console\Commands;

use App\Services\QuotationService;
use Illuminate\Console\Command;

class ExpireQuotations extends Command
{
    protected $signature = 'quotations:expire';
    protected $description = 'Expire old quotations and auto-disregard if no response';

    public function handle(QuotationService $quotationService): int
    {
        $expired = $quotationService->expireOldQuotations();
        
        $this->info("Expired {$expired} quotation(s)");
        
        return Command::SUCCESS;
    }
}
