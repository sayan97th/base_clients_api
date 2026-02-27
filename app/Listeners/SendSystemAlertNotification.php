<?php

namespace App\Listeners;

use App\Events\SystemAlert;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendSystemAlertNotification implements ShouldQueue
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    public function handle(SystemAlert $event): void
    {
        $this->notificationService->createNotification(
            user: $event->user,
            type: 'system',
            message: $event->message,
            extra: [
                'link' => $event->link,
            ],
        );
    }
}
