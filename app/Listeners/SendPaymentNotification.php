<?php

namespace App\Listeners;

use App\Events\PaymentCompleted;
use App\Services\NotificationService;
use App\Support\FrontendUrl;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendPaymentNotification implements ShouldQueue
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    public function handle(PaymentCompleted $event): void
    {
        $amount    = number_format($event->amount, 2);
        $mail_data = [];

        if ($event->invoice) {
            $invoice = $event->invoice->loadMissing(['lineItems', 'billedTo', 'order.orderCoupons.coupon']);

            $coupon_discounts = [];
            if ($invoice->order && $invoice->order->orderCoupons->isNotEmpty()) {
                $coupon_discounts = $invoice->order->orderCoupons->map(fn ($oc) => [
                    'code'            => $oc->coupon?->code ?? '',
                    'name'            => $oc->coupon?->name ?? '',
                    'discount_type'   => $oc->coupon?->discount_type ?? 'percentage',
                    'discount_value'  => $oc->coupon?->discount_value ?? 0,
                    'discount_amount' => (float) $oc->discount_amount,
                ])->toArray();
            }

            // PaymentCompleted is dispatched both for the paying client and for the
            // admin recipients resolved from Email Notification Settings (see
            // DispatchesAdminPaymentNotifications), each with its own $event->link:
            // an admin gets an /admin/invoices/{id} path while the client gets a
            // client-portal /invoices/{unique_id} path. There is no PDF download
            // route for invoices; the PDF export on the invoice page is generated
            // client-side, so no invoice_pdf_url is provided here.
            $invoice_link = $event->link ?? '/invoices/' . $invoice->unique_id;
            $invoice_url  = FrontendUrl::to($invoice_link);

            $mail_data = [
                'invoice_number'  => $invoice->invoice_number,
                'invoice_url'     => $invoice_url,
                'currency_type'   => $invoice->currency_type,
                'subtotal_amount' => $invoice->subtotal_amount,
                'total_amount'    => $invoice->total_amount,
                'credit_amount'   => $invoice->credit_amount,
                'line_items'      => $invoice->lineItems->map(fn ($item) => [
                    'name'       => $item->item_name,
                    'price'      => $item->price,
                    'quantity'   => $item->quantity,
                    'item_total' => $item->item_total,
                ])->toArray(),
                'billed_to' => $invoice->billedTo ? [
                    'company_name'   => $invoice->billedTo->company_name,
                    'address_line_1' => $invoice->billedTo->address_line_1,
                    'state'          => $invoice->billedTo->state,
                    'country'        => $invoice->billedTo->country,
                ] : null,
                'coupon_discounts' => $coupon_discounts,
            ];
        }

        $this->notificationService->createNotification(
            user: $event->user,
            type: 'payment',
            message: "{$event->payer_name} paid \${$amount} for invoice #{$event->invoice_number}.",
            extra: [
                'link'      => $event->link,
                'mail_data' => $mail_data,
            ],
        );
    }
}
