<?php

namespace App\Services;

use App\Events\PaymentCompleted;
use App\Models\ContentBriefOrder;
use App\Models\ContentOptimizationOrder;
use App\Models\Invoice;
use App\Models\InvoiceCouponDiscount;
use App\Models\LinkBuildingOrder;
use App\Models\NewContentOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    private const BULK_DISCOUNT_THRESHOLD = 10;
    private const BULK_DISCOUNT_RATE      = 0.10;

    /**
     * Create an invoice for a link building order.
     *
     * @param int|null $total_links  Total link quantity — used to determine bulk discount.
     *                               When null, computed from order items.
     */
    public function createForLinkBuildingOrder(
        User $user,
        LinkBuildingOrder $order,
        string $payment_method = 'Account Balance',
        string $currency_type = 'usd',
        float $credit_amount = 0.0,
        ?int $total_links = null
    ): Invoice {
        $order->loadMissing(['items.drTier', 'billing', 'orderCoupons.coupon']);

        $subtotal_amount = (float) $order->items->sum('subtotal');

        $resolved_total_links = $total_links ?? (int) $order->items->sum('quantity');
        $bulk_discount_amount = $resolved_total_links >= self::BULK_DISCOUNT_THRESHOLD
            ? round($order->subtotal_before_discount * self::BULK_DISCOUNT_RATE, 2)
            : 0.0;

        $line_items_data = $order->items->map(function ($item) {
            return [
                'item_name'    => $item->drTier ? $item->drTier->label . ' Link Building' : 'Link Building Service',
                'product_type' => 'link_building',
                'price'        => $item->unit_price,
                'quantity'     => $item->quantity,
                'item_total'   => $item->subtotal,
            ];
        })->all();

        $billing_data = [
            'company_name'        => $order->billing?->company ?? $user->organization?->name,
            'company_description' => $user->job_title ?? null,
            'address_line_1'      => $order->billing?->address,
            'address_line_2'      => null,
            'state'               => $order->billing?->state,
            'country'             => $order->billing?->country,
        ];

        $invoice = $this->buildInvoice(
            user:           $user,
            order_id:       $order->id,
            payment_method: $payment_method,
            currency_type:  $currency_type,
            subtotal_amount: $subtotal_amount,
            discount_amount: $bulk_discount_amount,
            discount_type:  $bulk_discount_amount > 0 ? 'bulk_10' : null,
            total_amount:   $order->total_amount,
            credit_amount:  $credit_amount,
            line_items:     $line_items_data,
            billing_data:   $billing_data,
            order_coupons:  $order->orderCoupons,
        );

        $payer_name = $user->full_name ?? $user->email;

        User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->each(function (User $admin) use ($invoice, $payer_name, $order) {
                event(new PaymentCompleted(
                    $admin,
                    $payer_name,
                    $order->total_amount,
                    $invoice->invoice_number,
                    '/invoices/' . $invoice->unique_id,
                    $invoice,
                ));
            });

        return $invoice;
    }

    public function createForNewContentOrder(
        User $user,
        NewContentOrder $order,
        string $payment_method = 'Account Balance',
        string $currency_type = 'usd',
        float $credit_amount = 0.0
    ): Invoice {
        $order->loadMissing(['items.tier', 'billing', 'orderCoupons.coupon']);

        $subtotal_amount = (float) $order->items->sum('subtotal');

        $line_items_data = $order->items->map(function ($item) {
            return [
                'item_name'    => $item->tier ? $item->tier->label : 'New Content Article',
                'product_type' => 'new_content',
                'price'        => $item->unit_price,
                'quantity'     => $item->quantity,
                'item_total'   => $item->subtotal,
            ];
        })->all();

        $billing_data = [
            'company_name'        => $order->billing?->company ?? $user->organization?->name,
            'company_description' => $user->job_title ?? null,
            'address_line_1'      => $order->billing?->address,
            'address_line_2'      => null,
            'state'               => $order->billing?->state,
            'country'             => $order->billing?->country,
        ];

        return $this->buildInvoice(
            user:            $user,
            order_id:        $order->id,
            payment_method:  $payment_method,
            currency_type:   $currency_type,
            subtotal_amount: $subtotal_amount,
            discount_amount: 0.0,
            discount_type:   null,
            total_amount:    $order->total_amount,
            credit_amount:   $credit_amount,
            line_items:      $line_items_data,
            billing_data:    $billing_data,
            order_coupons:   $order->orderCoupons,
        );
    }

    public function createForContentOptimizationOrder(
        User $user,
        ContentOptimizationOrder $order,
        string $payment_method = 'Account Balance',
        string $currency_type = 'usd',
        float $credit_amount = 0.0
    ): Invoice {
        $order->loadMissing(['items.tier', 'billing', 'orderCoupons.coupon']);

        $subtotal_amount = (float) $order->items->sum('subtotal');

        $line_items_data = $order->items->map(function ($item) {
            return [
                'item_name'    => $item->tier ? $item->tier->label : 'Content Optimization',
                'product_type' => 'content_optimization',
                'price'        => $item->unit_price,
                'quantity'     => $item->quantity,
                'item_total'   => $item->subtotal,
            ];
        })->all();

        $billing_data = [
            'company_name'        => $order->billing?->company ?? $user->organization?->name,
            'company_description' => $user->job_title ?? null,
            'address_line_1'      => $order->billing?->address,
            'address_line_2'      => null,
            'state'               => $order->billing?->state,
            'country'             => $order->billing?->country,
        ];

        return $this->buildInvoice(
            user:            $user,
            order_id:        $order->id,
            payment_method:  $payment_method,
            currency_type:   $currency_type,
            subtotal_amount: $subtotal_amount,
            discount_amount: 0.0,
            discount_type:   null,
            total_amount:    $order->total_amount,
            credit_amount:   $credit_amount,
            line_items:      $line_items_data,
            billing_data:    $billing_data,
            order_coupons:   $order->orderCoupons,
        );
    }

    public function createForContentBriefOrder(
        User $user,
        ContentBriefOrder $order,
        string $payment_method = 'Account Balance',
        string $currency_type = 'usd',
        float $credit_amount = 0.0
    ): Invoice {
        $order->loadMissing(['items.tier', 'billing', 'orderCoupons.coupon']);

        $subtotal_amount = (float) $order->items->sum('subtotal');

        $line_items_data = $order->items->map(function ($item) {
            return [
                'item_name'    => $item->tier ? $item->tier->label : 'Content Brief',
                'product_type' => 'content_brief',
                'price'        => $item->unit_price,
                'quantity'     => $item->quantity,
                'item_total'   => $item->subtotal,
            ];
        })->all();

        $billing_data = [
            'company_name'        => $order->billing?->company ?? $user->organization?->name,
            'company_description' => $user->job_title ?? null,
            'address_line_1'      => $order->billing?->address,
            'address_line_2'      => null,
            'state'               => $order->billing?->state,
            'country'             => $order->billing?->country,
        ];

        return $this->buildInvoice(
            user:            $user,
            order_id:        $order->id,
            payment_method:  $payment_method,
            currency_type:   $currency_type,
            subtotal_amount: $subtotal_amount,
            discount_amount: 0.0,
            discount_type:   null,
            total_amount:    $order->total_amount,
            credit_amount:   $credit_amount,
            line_items:      $line_items_data,
            billing_data:    $billing_data,
            order_coupons:   $order->orderCoupons,
        );
    }

    private function buildInvoice(
        User $user,
        string $order_id,
        string $payment_method,
        string $currency_type,
        float $subtotal_amount,
        float $discount_amount,
        ?string $discount_type,
        float $total_amount,
        float $credit_amount,
        array $line_items,
        array $billing_data,
        $order_coupons
    ): Invoice {
        return DB::transaction(function () use (
            $user, $order_id, $payment_method, $currency_type,
            $subtotal_amount, $discount_amount, $discount_type,
            $total_amount, $credit_amount, $line_items,
            $billing_data, $order_coupons
        ) {
            $unique_id      = strtoupper(bin2hex(random_bytes(4)));
            $invoice_number = 'BSM-' . str_pad(Invoice::count() + 1, 4, '0', STR_PAD_LEFT);

            $invoice = Invoice::create([
                'unique_id'       => $unique_id,
                'invoice_number'  => $invoice_number,
                'user_id'         => $user->id,
                'order_id'        => $order_id,
                'status'          => 'paid',
                'payment_method'  => $payment_method,
                'currency_type'   => $currency_type,
                'subtotal_amount' => $subtotal_amount,
                'discount_amount' => $discount_amount,
                'discount_type'   => $discount_type,
                'total_amount'    => $total_amount,
                'credit_amount'   => $credit_amount,
                'date_issued'     => now(),
                'date_due'        => now()->addDays(30),
                'date_paid'       => now(),
            ]);

            foreach ($line_items as $item) {
                $invoice->lineItems()->create([
                    'item_name'    => $item['item_name'],
                    'product_type' => $item['product_type'] ?? null,
                    'price'        => $item['price'],
                    'quantity'     => $item['quantity'],
                    'item_total'   => $item['item_total'],
                ]);
            }

            $invoice->billedTo()->create($billing_data);

            foreach ($order_coupons as $order_coupon) {
                $coupon = $order_coupon->coupon;

                if (! $coupon) {
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

            return $invoice->load(['lineItems', 'billedTo', 'couponDiscounts']);
        });
    }
}
