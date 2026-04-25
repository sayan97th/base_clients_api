<?php

namespace App\Services;

use App\Events\PaymentCompleted;
use App\Models\Invoice;
use App\Models\LinkBuildingOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    private const BULK_DISCOUNT_THRESHOLD = 10;
    private const BULK_DISCOUNT_RATE      = 0.10;

    /**
     * Create an invoice for a link building order.
     * Fires PaymentCompleted event to notify all super admins.
     *
     * @param int|null $total_links  Total link quantity across all items — used to determine bulk discount.
     *                               When null, it is computed from order items.
     */
    public function createForLinkBuildingOrder(
        User $user,
        LinkBuildingOrder $order,
        string $payment_method = 'Account Balance',
        string $currency_type = 'usd',
        float $credit_amount = 0.0,
        ?int $total_links = null
    ): Invoice {
        $order->loadMissing(['items.drTier', 'billing', 'orderCoupons']);

        $subtotal_amount = (float) $order->items->sum('subtotal');

        $resolved_total_links = $total_links ?? (int) $order->items->sum('quantity');
        $bulk_discount_amount = $resolved_total_links >= self::BULK_DISCOUNT_THRESHOLD
            ? round($order->subtotal_before_discount * self::BULK_DISCOUNT_RATE, 2)
            : 0.0;

        $total_amount = $order->total_amount;

        $invoice = DB::transaction(function () use (
            $user, $order, $payment_method, $currency_type,
            $subtotal_amount, $bulk_discount_amount, $total_amount, $credit_amount
        ) {
            $unique_id      = strtoupper(bin2hex(random_bytes(4)));
            $invoice_number = 'BSM-' . str_pad(Invoice::count() + 1, 4, '0', STR_PAD_LEFT);

            $invoice = Invoice::create([
                'unique_id'       => $unique_id,
                'invoice_number'  => $invoice_number,
                'user_id'         => $user->id,
                'order_id'        => $order->id,
                'status'          => 'paid',
                'payment_method'  => $payment_method,
                'currency_type'   => $currency_type,
                'subtotal_amount'  => $subtotal_amount,
                'discount_amount'  => $bulk_discount_amount,
                'total_amount'     => $total_amount,
                'credit_amount'   => $credit_amount,
                'date_issued'     => now(),
                'date_due'        => now()->addDays(30),
                'date_paid'       => now(),
            ]);

            foreach ($order->items as $item) {
                $item_name = $item->drTier
                    ? $item->drTier->label . ' Link Building'
                    : 'Link Building Service';

                $invoice->lineItems()->create([
                    'item_name'  => $item_name,
                    'price'      => $item->unit_price,
                    'quantity'   => $item->quantity,
                    'item_total' => $item->subtotal,
                ]);
            }

            $billing = $order->billing;
            $invoice->billedTo()->create([
                'company_name'        => $billing?->company ?? $user->organization?->name,
                'company_description' => $user->job_title ?? null,
                'address_line_1'      => $billing?->address,
                'address_line_2'      => null,
                'state'               => $billing?->state,
                'country'             => $billing?->country,
            ]);

            return $invoice->load(['lineItems', 'billedTo']);
        });

        $payer_name = $user->full_name ?? $user->email;

            User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
                ->each(function (User $admin) use ($invoice, $payer_name, $total_amount) {
                    event(new PaymentCompleted(
                        $admin,
                        $payer_name,
                        $total_amount,
                        $invoice->invoice_number,
                        '/invoices/' . $invoice->unique_id,
                        $invoice,
                    ));
                });

        return $invoice;
    }
}