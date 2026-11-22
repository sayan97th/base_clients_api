<?php

use App\Models\LinkBuildingOrderHistoryBatch;
use App\Models\LinkBuildingOrderPlacementRevision;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('notifications:unsnooze')->everyFiveMinutes();

// SEO subscription renewals, payment retries and renewal reminders. Runs
// hourly during business hours (Mountain Time) so a missed run is picked up the same day;
// each period is billed at most once, so repeated runs never double charge.
Schedule::command('seo-subscriptions:process-billing')
    ->hourlyAt(5)
    ->between('8:00', '16:00')
    ->timezone('America/Denver')
    ->withoutOverlapping(30)
    ->onOneServer();

// Link Building Orders edit history older than config('link_building.history_retention_days').
Schedule::command('model:prune', ['--model' => [
    LinkBuildingOrderPlacementRevision::class,
    LinkBuildingOrderHistoryBatch::class,
]])->daily();
