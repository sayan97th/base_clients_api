<?php

namespace App\Listeners;

use App\Events\PayLaterOrderPlaced;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendPayLaterOrderNotification implements ShouldQueue
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    public function handle(PayLaterOrderPlaced $event): void
    {
        $amount = number_format($event->amount, 2);

        $this->notificationService->createNotification(
            user: $event->user,
            type: 'order',
            message: "{$event->client_name} placed a Pay Later order, invoice #{$event->invoice_number} is awaiting payment (\${$amount}).",
            extra: [
                'link'            => $event->link,
                'mail_data'       => ['skip_email' => true],
            ],
        );
    }
}
