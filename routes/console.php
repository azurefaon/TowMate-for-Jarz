<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('towmate:purge-expired-data')->daily();
Schedule::command('towmate:sync-quotation-lifecycle')->daily();
Schedule::command('quotations:expire')->everyFiveMinutes();
Schedule::command('quotations:followup')->daily();
