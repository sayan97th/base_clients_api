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

// Link Building Orders edit history older than config('link_building.history_retention_days').
Schedule::command('model:prune', ['--model' => [
    LinkBuildingOrderPlacementRevision::class,
    LinkBuildingOrderHistoryBatch::class,
]])->daily();
