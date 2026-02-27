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
            message: "New post published: {$event->post_title}.",
            extra: [
                'preview_text' => $event->preview_text,
                'link' => $event->link,
            ],
        );
    }
}
