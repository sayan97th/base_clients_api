<?php

namespace App\Listeners;

use App\Events\SubscriptionCreated;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendSubscriptionNotification implements ShouldQueue
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    public function handle(SubscriptionCreated $event): void
    {
        $this->notificationService->createNotification(
            user: $event->user,
            type: 'payment',
            message: "A new subscription to {$event->plan_name} has been added to your account.",
            extra: [
                'link' => $event->link,
            ],
        );
    }
}
