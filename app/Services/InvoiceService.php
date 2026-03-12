<?php

namespace App\Services;

use App\Events\PaymentCompleted;
use App\Models\Invoice;
use App\Models\LinkBuildingOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    /**
     * Create an invoice for a link building order.
     * Fires PaymentCompleted event to notify all super admins.
     */
    public function createForLinkBuildingOrder(
        User $user,
        LinkBuildingOrder $order,
        string $payment_method = 'Account Balance',
        string $currency_type = 'usd',
        float $credit_amount = 0.0
    ): Invoice {
        $order->loadMissing(['items.drTier', 'billing']);

        $subtotal_amount = $order->items->sum('subtotal');
        $total_amount    = $order->total_amount;

        $invoice = DB::transaction(function () use (
            $user, $order, $payment_method, $currency_type,
            $subtotal_amount, $total_amount, $credit_amount
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
                'subtotal_amount' => $subtotal_amount,
                'total_amount'    => $total_amount,
                'credit_amount'   => $credit_amount,
                'date_issued'     => now(),
                'date_due'        => now()->addDays(30),
                'date_paid'       => now(),
            ]);

            foreach ($order->items as $item) {
                $item_name = $item->drTier
                    ? $item->drTier->dr_label . ' Link Building'
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
                ));
            });

        return $invoice;
    }
}
