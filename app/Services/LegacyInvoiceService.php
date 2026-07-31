<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceCouponDiscount;
use App\Models\LinkBuildingOrder;
use Illuminate\Support\Facades\DB;

/**
 * Generates and keeps in sync the Invoice record for a legacy-imported
 * link-building order. Centralized here so that both the one-off
 * `invoices:generate-legacy` command and the recurring `orders:import --update`
 * command produce invoices that always reflect the order's current
 * total/items — preventing invoices from drifting out of sync with the
 * order whenever the legacy CSV data is re-imported and the order's
 * total_amount or items change.
 */
class LegacyInvoiceService
{
    private const ORDER_STATUS_TO_INVOICE_STATUS = [
        'completed'       => 'paid',
        'processing'      => 'unpaid',
        'pending'         => 'unpaid',
        'payment_pending' => 'unpaid',
        'cancelled'       => 'void',
    ];

    public function __construct(
        private readonly InvoiceNumberGenerator $invoice_number_generator
    ) {}

    /**
     * Create a brand-new invoice for a legacy order that doesn't have one yet.
     */
    public function generate(LinkBuildingOrder $order): Invoice
    {
        $order->loadMissing(['items.drTier', 'user', 'orderCoupons.coupon']);

        return $this->invoice_number_generator->transact(function () use ($order): Invoice {
            $unique_id      = strtoupper(bin2hex(random_bytes(4)));
            $invoice_number = $this->invoice_number_generator->next();

            $date_issued = $order->created_at ?? now();
            $status      = $this->resolveInvoiceStatus($order->status);

            $invoice = Invoice::create([
                'unique_id'       => $unique_id,
                'invoice_number'  => $invoice_number,
                'user_id'         => $order->user_id,
                'order_id'        => $order->id,
                'session_id'      => $order->session_id,
                'session_title'   => $order->session_title,
                'status'          => $status,
                'payment_method'  => $status === 'paid' ? 'Account Balance' : 'Pending',
                'currency_type'   => 'usd',
                'subtotal_amount' => (float) $order->items->sum('subtotal'),
                'discount_amount' => (float) ($order->coupon_discount_amount ?? 0.0),
                'discount_type'   => ($order->coupon_discount_amount ?? 0.0) > 0 ? 'legacy' : null,
                'total_amount'    => (float) $order->total_amount,
                'credit_amount'   => 0.0,
                'notes'           => $order->order_notes,
                'date_issued'     => $date_issued,
                'date_due'        => $date_issued,
                'date_paid'       => $status === 'paid' ? $date_issued : null,
            ]);

            $this->syncLineItems($invoice, $order);
            $this->syncCouponDiscounts($invoice, $order);

            return $invoice;
        });
    }

    /**
     * Refresh an existing invoice's amounts and line items from the order's
     * current state. Used whenever the underlying legacy order is re-imported
     * and its total_amount or items may have changed.
     */
    public function refresh(Invoice $invoice, LinkBuildingOrder $order): Invoice
    {
        $order->loadMissing(['items.drTier', 'user', 'orderCoupons.coupon']);

        return DB::transaction(function () use ($invoice, $order): Invoice {
            $date_issued = $order->created_at ?? now();
            $status      = $this->resolveInvoiceStatus($order->status);

            $invoice->forceFill([
                'session_id'      => $order->session_id,
                'session_title'   => $order->session_title,
                'status'          => $status,
                'payment_method'  => $status === 'paid' ? 'Account Balance' : 'Pending',
                'subtotal_amount' => (float) $order->items->sum('subtotal'),
                'discount_amount' => (float) ($order->coupon_discount_amount ?? 0.0),
                'discount_type'   => ($order->coupon_discount_amount ?? 0.0) > 0 ? 'legacy' : null,
                'total_amount'    => (float) $order->total_amount,
                'notes'           => $order->order_notes,
                'date_issued'     => $date_issued,
                'date_due'        => $date_issued,
                'date_paid'       => $status === 'paid' ? $date_issued : null,
            ]);
            $invoice->save();

            $invoice->lineItems()->delete();
            $invoice->couponDiscounts()->delete();

            $this->syncLineItems($invoice, $order);
            $this->syncCouponDiscounts($invoice, $order);

            return $invoice;
        });
    }

    /**
     * Create or refresh the invoice for a legacy order, whichever applies.
     * This is the entry point used after a legacy order has just been
     * imported/updated, so its invoice (if any) never drifts out of sync.
     *
     * @return array{invoice: Invoice, action: string}
     */
    public function syncForOrder(LinkBuildingOrder $order): array
    {
        $existing = Invoice::where('order_id', $order->id)->first();

        if ($existing) {
            return ['invoice' => $this->refresh($existing, $order), 'action' => 'updated'];
        }

        return ['invoice' => $this->generate($order), 'action' => 'generated'];
    }

    public function resolveInvoiceStatus(string $order_status): string
    {
        return self::ORDER_STATUS_TO_INVOICE_STATUS[$order_status] ?? 'unpaid';
    }

    private function syncLineItems(Invoice $invoice, LinkBuildingOrder $order): void
    {
        foreach ($order->items as $item) {
            $item_name = $item->drTier
                ? $item->drTier->label . ' Link Building'
                : 'Link Building Service';

            $invoice->lineItems()->create([
                'order_id'     => $order->id,
                'item_name'    => $item_name,
                'product_type' => 'link_building',
                'price'        => $item->unit_price,
                'quantity'     => $item->quantity,
                'item_total'   => $item->subtotal,
            ]);
        }
    }

    private function syncCouponDiscounts(Invoice $invoice, LinkBuildingOrder $order): void
    {
        foreach ($order->orderCoupons as $order_coupon) {
            $coupon = $order_coupon->coupon;

            if (!$coupon) {
                continue;
            }

            InvoiceCouponDiscount::create([
                'invoice_id'      => $invoice->id,
                'code'            => $coupon->code,
                'name'            => $coupon->name ?? null,
                'discount_type'   => $coupon->discount_type,
                'discount_value'  => $coupon->discount_value,
                'discount_amount' => $order_coupon->discount_amount,
            ]);
        }
    }
}
