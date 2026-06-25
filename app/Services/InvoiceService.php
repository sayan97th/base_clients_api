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
    private const BULK_DISCOUNT_THRESHOLD   = 10;
    private const BULK_DISCOUNT_RATE        = 0.10;
    public const DEFERRED_PAYMENT_DUE_DAYS = 7;

    /**
     * Create an invoice for a link building order.
     *
     * @param int|null $total_links  Total link quantity — used to determine bulk discount.
     */
    public function createForLinkBuildingOrder(
        User $user,
        LinkBuildingOrder $order,
        string $payment_method = 'Account Balance',
        string $currency_type = 'usd',
        float $credit_amount = 0.0,
        ?int $total_links = null,
        string $invoice_status = 'paid',
        int $due_days = 30,
        ?string $payment_intent_id = null
    ): Invoice {
        $order->loadMissing(['items.drTier', 'billing', 'orderCoupons.coupon']);

        $subtotal_amount = (float) $order->items->sum('subtotal');

        $resolved_total_links = $total_links ?? (int) $order->items->sum('quantity');

        // Bulk discount only applies when no coupon won — only one discount type is applied per order.
        $has_coupon_applied   = $order->orderCoupons->isNotEmpty();
        $bulk_discount_amount = (! $has_coupon_applied && $resolved_total_links >= self::BULK_DISCOUNT_THRESHOLD)
            ? round($order->subtotal_before_discount * self::BULK_DISCOUNT_RATE, 2)
            : 0.0;

        $line_items_data = $order->items->map(function ($item) use ($order) {
            return [
                'item_name'    => $item->drTier ? $item->drTier->label . ' Link Building' : 'Link Building Service',
                'product_type' => 'link_building',
                'order_id'     => $order->id,
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
            user:               $user,
            order_id:           $order->id,
            payment_method:     $payment_method,
            currency_type:      $currency_type,
            subtotal_amount:    $subtotal_amount,
            discount_amount:    $bulk_discount_amount,
            discount_type:      $bulk_discount_amount > 0 ? 'bulk_10' : null,
            total_amount:       $order->total_amount,
            credit_amount:      $credit_amount,
            line_items:         $line_items_data,
            billing_data:       $billing_data,
            order_coupons:      $order->orderCoupons,
            invoice_status:     $invoice_status,
            due_days:           $due_days,
            payment_intent_id:  $payment_intent_id,
        );

        if ($invoice_status === 'paid') {
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
        }

        return $invoice;
    }

    public function createForNewContentOrder(
        User $user,
        NewContentOrder $order,
        string $payment_method = 'Account Balance',
        string $currency_type = 'usd',
        float $credit_amount = 0.0,
        string $invoice_status = 'paid',
        int $due_days = 30,
        ?string $payment_intent_id = null
    ): Invoice {
        $order->loadMissing(['items.tier', 'billing', 'orderCoupons.coupon']);

        $subtotal_amount = (float) $order->items->sum('subtotal');

        $line_items_data = $order->items->map(function ($item) use ($order) {
            return [
                'item_name'    => $item->tier ? $item->tier->label : 'New Content Article',
                'product_type' => 'new_content',
                'order_id'     => $order->id,
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
            user:              $user,
            order_id:          $order->id,
            payment_method:    $payment_method,
            currency_type:     $currency_type,
            subtotal_amount:   $subtotal_amount,
            discount_amount:   0.0,
            discount_type:     null,
            total_amount:      $order->total_amount,
            credit_amount:     $credit_amount,
            line_items:        $line_items_data,
            billing_data:      $billing_data,
            order_coupons:     $order->orderCoupons,
            invoice_status:    $invoice_status,
            due_days:          $due_days,
            payment_intent_id: $payment_intent_id,
        );

        if ($invoice_status === 'paid') {
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
        }

        return $invoice;
    }

    public function createForContentOptimizationOrder(
        User $user,
        ContentOptimizationOrder $order,
        string $payment_method = 'Account Balance',
        string $currency_type = 'usd',
        float $credit_amount = 0.0,
        string $invoice_status = 'paid',
        int $due_days = 30,
        ?string $payment_intent_id = null
    ): Invoice {
        $order->loadMissing(['items.tier', 'billing', 'orderCoupons.coupon']);

        $subtotal_amount = (float) $order->items->sum('subtotal');

        $line_items_data = $order->items->map(function ($item) use ($order) {
            return [
                'item_name'    => $item->tier ? $item->tier->label : 'Content Optimization',
                'product_type' => 'content_optimization',
                'order_id'     => $order->id,
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
            user:              $user,
            order_id:          $order->id,
            payment_method:    $payment_method,
            currency_type:     $currency_type,
            subtotal_amount:   $subtotal_amount,
            discount_amount:   0.0,
            discount_type:     null,
            total_amount:      $order->total_amount,
            credit_amount:     $credit_amount,
            line_items:        $line_items_data,
            billing_data:      $billing_data,
            order_coupons:     $order->orderCoupons,
            invoice_status:    $invoice_status,
            due_days:          $due_days,
            payment_intent_id: $payment_intent_id,
        );

        if ($invoice_status === 'paid') {
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
        }

        return $invoice;
    }

    public function createForContentBriefOrder(
        User $user,
        ContentBriefOrder $order,
        string $payment_method = 'Account Balance',
        string $currency_type = 'usd',
        float $credit_amount = 0.0,
        string $invoice_status = 'paid',
        int $due_days = 30,
        ?string $payment_intent_id = null
    ): Invoice {
        $order->loadMissing(['items.tier', 'billing', 'orderCoupons.coupon']);

        $subtotal_amount = (float) $order->items->sum('subtotal');

        $line_items_data = $order->items->map(function ($item) use ($order) {
            return [
                'item_name'    => $item->tier ? $item->tier->label : 'Content Brief',
                'product_type' => 'content_brief',
                'order_id'     => $order->id,
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
            user:              $user,
            order_id:          $order->id,
            payment_method:    $payment_method,
            currency_type:     $currency_type,
            subtotal_amount:   $subtotal_amount,
            discount_amount:   0.0,
            discount_type:     null,
            total_amount:      $order->total_amount,
            credit_amount:     $credit_amount,
            line_items:        $line_items_data,
            billing_data:      $billing_data,
            order_coupons:     $order->orderCoupons,
            invoice_status:    $invoice_status,
            due_days:          $due_days,
            payment_intent_id: $payment_intent_id,
        );

        if ($invoice_status === 'paid') {
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
        }

        return $invoice;
    }

    /**
     * Create a single invoice covering all products in a multi-product cart session.
     *
     * @param array $product_entries  Each entry: ['product_type' => string, 'model' => Order, 'total_links' => ?int]
     */
    public function createForMultiProductSession(
        User $user,
        string $session_id,
        ?string $session_title,
        array $product_entries,
        string $payment_method = 'Credit Card',
        string $currency_type = 'usd',
        float $credit_amount = 0.0,
        string $invoice_status = 'paid',
        int $due_days = 30,
        ?string $payment_intent_id = null
    ): Invoice {
        $all_line_items    = [];
        $subtotal_amount   = 0.0;
        $discount_amount   = 0.0;
        $total_amount      = 0.0;
        $primary_order_id  = null;
        $billing_data      = null;
        $all_order_coupons = collect();

        $label_map = [
            'link_building'        => 'Link Building',
            'new_content'          => 'New Content',
            'content_optimization' => 'Content Optimization',
            'content_brief'        => 'Content Brief',
        ];

        foreach ($product_entries as $entry) {
            /** @var LinkBuildingOrder|NewContentOrder|ContentOptimizationOrder|ContentBriefOrder $order */
            $order        = $entry['model'];
            $product_type = $entry['product_type'];

            $relations = match ($product_type) {
                'link_building' => ['items.drTier', 'billing', 'orderCoupons.coupon'],
                default         => ['items.tier', 'billing', 'orderCoupons.coupon'],
            };

            $order->loadMissing($relations);

            if ($primary_order_id === null) {
                $primary_order_id = $order->id;
            }

            if ($billing_data === null && $order->billing) {
                $billing_data = [
                    'company_name'        => $order->billing->company ?? $user->organization?->name,
                    'company_description' => $user->job_title ?? null,
                    'address_line_1'      => $order->billing->address,
                    'address_line_2'      => null,
                    'state'               => $order->billing->state,
                    'country'             => $order->billing->country,
                ];
            }

            $all_order_coupons = $all_order_coupons->merge($order->orderCoupons);
            $total_amount     += (float) $order->total_amount;

            if ($product_type === 'link_building') {
                $total_links = isset($entry['total_links']) ? (int) $entry['total_links'] : (int) $order->items->sum('quantity');
                $order_subtotal = (float) $order->items->sum('subtotal');
                $subtotal_amount += $order_subtotal;

                // Bulk discount only applies when no coupon won — only one discount type per order.
                $has_coupon_applied = $order->orderCoupons->isNotEmpty();
                if (! $has_coupon_applied && $total_links >= self::BULK_DISCOUNT_THRESHOLD) {
                    $discount_amount += round($order->subtotal_before_discount * self::BULK_DISCOUNT_RATE, 2);
                }

                foreach ($order->items as $item) {
                    $all_line_items[] = [
                        'item_name'    => $item->drTier ? $item->drTier->label . ' Link Building' : 'Link Building Service',
                        'product_type' => $product_type,
                        'order_id'     => $order->id,
                        'price'        => $item->unit_price,
                        'quantity'     => $item->quantity,
                        'item_total'   => $item->subtotal,
                    ];
                }
            } else {
                $subtotal_amount += (float) $order->items->sum('subtotal');

                foreach ($order->items as $item) {
                    $item_name = $item->tier
                        ? $item->tier->label
                        : ($label_map[$product_type] ?? ucwords(str_replace('_', ' ', $product_type)));

                    $all_line_items[] = [
                        'item_name'    => $item_name,
                        'product_type' => $product_type,
                        'order_id'     => $order->id,
                        'price'        => $item->unit_price,
                        'quantity'     => $item->quantity,
                        'item_total'   => $item->subtotal,
                    ];
                }
            }
        }

        $subtotal_amount = round($subtotal_amount, 2);
        $discount_amount = round($discount_amount, 2);
        $total_amount    = round($total_amount, 2);

        $invoice = $this->buildInvoice(
            user:            $user,
            order_id:        $primary_order_id,
            session_id:      $session_id,
            session_title:   $session_title,
            payment_method:  $payment_method,
            currency_type:   $currency_type,
            subtotal_amount: $subtotal_amount,
            discount_amount:   $discount_amount,
            discount_type:     $discount_amount > 0 ? 'bulk' : null,
            total_amount:      $total_amount,
            credit_amount:     $credit_amount,
            line_items:        $all_line_items,
            billing_data:      $billing_data ?? [],
            order_coupons:     $all_order_coupons,
            invoice_status:    $invoice_status,
            due_days:          $due_days,
            payment_intent_id: $payment_intent_id,
        );

        if ($invoice_status === 'paid') {
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
        }

        return $invoice;
    }

    private function buildInvoice(
        User $user,
        ?string $order_id,
        string $payment_method,
        string $currency_type,
        float $subtotal_amount,
        float $discount_amount,
        ?string $discount_type,
        float $total_amount,
        float $credit_amount,
        array $line_items,
        array $billing_data,
        $order_coupons,
        ?string $session_id = null,
        ?string $session_title = null,
        string $invoice_status = 'paid',
        int $due_days = 30,
        ?string $payment_intent_id = null
    ): Invoice {
        return DB::transaction(function () use (
            $user, $order_id, $session_id, $session_title,
            $payment_method, $currency_type,
            $subtotal_amount, $discount_amount, $discount_type,
            $total_amount, $credit_amount, $line_items,
            $billing_data, $order_coupons,
            $invoice_status, $due_days, $payment_intent_id
        ) {
            $unique_id      = strtoupper(bin2hex(random_bytes(4)));
            $invoice_number = 'BSM-' . str_pad(Invoice::count() + 1, 4, '0', STR_PAD_LEFT);

            $invoice = Invoice::create([
                'unique_id'          => $unique_id,
                'invoice_number'     => $invoice_number,
                'user_id'            => $user->id,
                'order_id'           => $order_id,
                'session_id'         => $session_id,
                'session_title'      => $session_title,
                'status'             => $invoice_status,
                'payment_method'     => $invoice_status === 'paid' ? $payment_method : 'Pending',
                'currency_type'      => $currency_type,
                'subtotal_amount'    => $subtotal_amount,
                'discount_amount'    => $discount_amount,
                'discount_type'      => $discount_type,
                'total_amount'       => $total_amount,
                'credit_amount'      => $credit_amount,
                'payment_intent_id'  => $payment_intent_id,
                'date_issued'        => now(),
                'date_due'           => now()->addDays($due_days),
                'date_paid'          => $invoice_status === 'paid' ? now() : null,
            ]);

            foreach ($line_items as $item) {
                $invoice->lineItems()->create([
                    'order_id'     => $item['order_id'] ?? null,
                    'item_name'    => $item['item_name'],
                    'product_type' => $item['product_type'] ?? null,
                    'price'        => $item['price'],
                    'quantity'     => $item['quantity'],
                    'item_total'   => $item['item_total'],
                ]);
            }

            if (! empty($billing_data)) {
                $invoice->billedTo()->create($billing_data);
            }

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
