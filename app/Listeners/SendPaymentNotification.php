<?php

namespace App\Listeners;

use App\Events\PaymentCompleted;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendPaymentNotification implements ShouldQueue
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    public function handle(PaymentCompleted $event): void
    {
        $amount = number_format($event->amount, 2);

        $this->notificationService->createNotification(
            user: $event->user,
            type: 'payment',
            message: "{$event->user->full_name} paid \${$amount} for invoice #{$event->invoice_number}.",
            extra: [
                'link' => $event->link,
            ],
        );
    }
}
