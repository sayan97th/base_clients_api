<?php

namespace App\Listeners;

use App\Events\PostPublished;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendPostNotification implements ShouldQueue
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    public function handle(PostPublished $event): void
    {
        $this->notificationService->createNotification(
            user: $event->user,
            type: 'post',
            message: "BASE Search Marketing posted a message in {$event->service_name}.",
            extra: [
                'preview_text' => $event->preview_text,
                'link'         => $event->link,
            ],
        );
    }
}
