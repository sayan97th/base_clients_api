<?php

namespace App\Listeners;

use App\Events\LinkBuildingOrderPlaced;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendLinkBuildingOrderNotification implements ShouldQueue
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    public function handle(LinkBuildingOrderPlaced $event): void
    {
        $order_id     = $event->order->id;
        $total_links  = $event->total_links;
        $total_amount = number_format($event->order->total_amount, 2);

        $this->notificationService->createNotification(
            user: $event->user,
            type: 'order',
            message: 'Your link building order has been placed successfully.',
            extra: [
                'preview_text' => "Order #{$order_id} · {$total_links} link(s) · \${$total_amount}",
                'link'         => "/link-building/orders/{$order_id}",
            ],
        );
    }
}
