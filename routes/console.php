<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('towmate:sync-quotation-lifecycle')->daily();
Schedule::command('quotations:expire')->everyFiveMinutes();
// Runs this often so Book Now quotations' 30-minute reminder (see
// QuotationService::getQuotationsNeedingFollowUp()) actually fires before
// their 1-hour expiry — the 5-day scheduled-booking reminder still works
// fine at this cadence since follow_up_sent_at prevents duplicate sends.
Schedule::command('quotations:followup')->everyFiveMinutes();
Schedule::command('bookings:expire-scheduled')->hourly();
Schedule::command('towmate:purge-deleted-users')->daily();
Schedule::command('towmate:lock-inactive-customers')->daily();
