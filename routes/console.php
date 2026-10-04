<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('notifications:unsnooze')->everyFiveMinutes();

// Autopay: charges due invoices to the client's saved card and reconciles any
// interrupted charge. Runs from cron (schedule:run), not from queue workers, so
// it keeps working if supervisor is down. withoutOverlapping + onOneServer make
// sure two runs never charge in parallel.
Schedule::command('invoices:process-autopay')
    ->hourly()
    ->withoutOverlapping(55)
    ->onOneServer();
