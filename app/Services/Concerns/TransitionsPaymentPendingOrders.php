<?php

namespace App\Services\Concerns;

use App\Models\ContentBriefOrder;
use App\Models\ContentOptimizationOrder;
use App\Models\Invoice;
use App\Models\LinkBuildingOrder;
use App\Models\NewContentOrder;
use App\Services\OrderDetailsService;

/**
 * After a deferred invoice is paid, transition all associated
 * payment_pending orders so work can begin. An order whose intake details
 * are still missing lands in `pending_details` (staying visible on the
 * dashboards) instead of `new_request`; a complete Link Building order also
 * has its turnaround clock started. Delegated to OrderDetailsService so the
 * paid-order transition logic lives in exactly one place.
 *
 * Shared by every invoice payment path (pay page, public share link,
 * autopay and the admin "Charge card on file" action).
 */
trait TransitionsPaymentPendingOrders
{
    private function updatePaymentPendingOrders(Invoice $invoice, ?string $payment_intent_id): void
    {
        $order_models = [
            LinkBuildingOrder::class,
            NewContentOrder::class,
            ContentOptimizationOrder::class,
            ContentBriefOrder::class,
        ];

        $query = function (string $model) use ($invoice) {
            if ($invoice->session_id) {
                return $model::where('session_id', $invoice->session_id)
                    ->where('status', 'payment_pending');
            }

            if ($invoice->order_id) {
                return $model::where('id', $invoice->order_id)
                    ->where('status', 'payment_pending');
            }

            return null;
        };

        $order_details_service = app(OrderDetailsService::class);

        foreach ($order_models as $model) {
            $builder = $query($model);

            if ($builder === null) {
                return;
            }

            foreach ($builder->get() as $order) {
                $order->payment_intent_id = $payment_intent_id;
                $order->save();

                // Resolves to new_request (details complete) or pending_details,
                // and starts the Link Building clock when the order is complete.
                // An invoice created from a "Skip for now" Pay Later checkout
                // carries details_deferred=true, which forces pending_details
                // here regardless of how much intake data ended up getting filled
                // in before the invoice was paid — matching the immediate
                // card-payment checkout path.
                $order_details_service->applyPaidStatus($order, (bool) $invoice->details_deferred);
            }
        }
    }
}
