<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

class UnsnoozeNotifications extends Command
{
    protected $signature = 'notifications:unsnooze';
    protected $description = 'Reactivate snoozed notifications that have passed their snooze time';

    public function handle(NotificationService $notificationService): int
    {
        $count = $notificationService->unsnoozeExpired();

        $this->info("Unsnoozed {$count} notification(s).");

        return self::SUCCESS;
    }
}
