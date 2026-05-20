<?php

namespace App\Http\Controllers\Client\Cart;

use App\Events\LinkBuildingOrderPlaced;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\CheckoutCartRequest;
use App\Http\Requests\Cart\UpsertCartRequest;
use App\Models\Cart;
use App\Models\ContentBriefOrder;
use App\Models\ContentOptimizationOrder;
use App\Models\Coupon;
use App\Models\CreditTransaction;
use App\Models\LinkBuildingOrder;
use App\Models\NewContentOrder;
use App\Models\User;
use App\Services\CouponService;
use App\Services\InvoiceService;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CartController extends Controller
{
    private const BULK_DISCOUNT_THRESHOLD = 10;
    private const BULK_DISCOUNT_RATE      = 0.10;

    public function __construct(
        protected StripeService $stripeService,
        protected CouponService $couponService,
        protected InvoiceService $invoiceService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $cart = Cart::where('user_id', $request->user()->id)->first();

        return response()->json(['data' => $cart?->payload]);
    }

    public function upsert(UpsertCartRequest $request): JsonResponse
    {
        Cart::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['payload' => $request->validated()]
        );

        return response()->json(['message' => 'Cart saved successfully.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        Cart::where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => 'Cart deleted successfully.']);
    }

    public function checkout(CheckoutCartRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $session_id = $request->input('session_id');

        if ($session_id) {
            $already_processed = LinkBuildingOrder::where('session_id', $session_id)->exists()
                || ContentOptimizationOrder::where('session_id', $session_id)->exists()
                || NewContentOrder::where('session_id', $session_id)->exists()
                || ContentBriefOrder::where('session_id', $session_id)->exists();

            if ($already_processed) {
                return response()->json([
                    'message' => 'This checkout session has already been processed.',
                ], 409);
            }
        }

        $payment_method_id  = $request->input('payment_method_id');
        $is_credits_payment = str_starts_with($payment_method_id, 'credits_');

        if ($is_credits_payment) {
            $credit_transaction_id = (int) substr($payment_method_id, strlen('credits_'));
            $credit_transaction    = CreditTransaction::find($credit_transaction_id);

            if (
                ! $credit_transaction
                || $credit_transaction->user_id !== $user->id
                || $credit_transaction->type !== 'debit'
            ) {
                return response()->json([
                    'message' => 'Invalid credit payment reference.',
                    'error'   => 'The provided credit transaction is not valid for this payment.',
                ], 422);
            }

            // Credits already represent a discounted value — no additional
            // discounts or coupon codes may be applied on top of them.
            if (! empty($request->input('coupon_ids', []))) {
                return response()->json([
                    'message' => 'Coupon codes cannot be applied when paying with account credits.',
                ], 422);
            }
        } else {
            // Verify the Stripe PaymentIntent before writing anything to the database
            $stripe_result = $this->stripeService->verifyPaymentIntent($payment_method_id);

            if (! $stripe_result['verified']) {
                return response()->json([
                    'message' => 'Payment could not be processed.',
                    'error'   => $stripe_result['message'] ?? 'Your payment could not be verified.',
                ], 402);
            }
        }

        // Validate that all submitted coupon IDs still exist
        $coupon_ids     = $request->input('coupon_ids', []);
        $coupon_models  = [];

        foreach ($coupon_ids as $coupon_id) {
            $coupon = Coupon::find($coupon_id);

            if (!$coupon) {
                return response()->json(['message' => 'One or more coupons are no longer valid.'], 422);
            }

            $coupon_models[$coupon_id] = $coupon;
        }

        $billing       = $request->input('billing');
        $order_title   = $request->input('order_title');
        $order_notes   = $request->input('order_notes');
        $created_orders = [];

        $link_building_items        = $request->input('link_building_items');
        $content_optimization_items = $request->input('content_optimization_items');
        $new_content_items          = $request->input('new_content_items');
        $content_brief_items        = $request->input('content_brief_items');

        $session_id    = $request->input('session_id') ?? (string) Str::uuid();
        $session_title = $order_title;

        try {
            DB::transaction(function () use (
                $user, $payment_method_id, $billing, $order_title, $order_notes,
                $coupon_models, $session_id, $session_title, $is_credits_payment,
                $link_building_items, $content_optimization_items,
                $new_content_items, $content_brief_items,
                &$created_orders
            ) {
                if (! empty($link_building_items)) {
                    $created_orders[] = $this->createLinkBuildingOrder(
                        $user, $link_building_items, $billing,
                        $payment_method_id, $coupon_models,
                        $order_title, $order_notes, $session_id, $session_title,
                        $is_credits_payment
                    );
                }

                if (! empty($content_optimization_items)) {
                    $created_orders[] = $this->createContentOptimizationOrder(
                        $user, $content_optimization_items, $billing,
                        $payment_method_id, $coupon_models,
                        $order_title, $order_notes, $session_id, $session_title,
                        $is_credits_payment
                    );
                }

                if (! empty($new_content_items)) {
                    $created_orders[] = $this->createNewContentOrder(
                        $user, $new_content_items, $billing,
                        $payment_method_id, $coupon_models,
                        $order_title, $order_notes, $session_id, $session_title,
                        $is_credits_payment
                    );
                }

                if (! empty($content_brief_items)) {
                    $created_orders[] = $this->createContentBriefOrder(
                        $user, $content_brief_items, $billing,
                        $payment_method_id, $coupon_models,
                        $order_title, $order_notes, $session_id, $session_title,
                        $is_credits_payment
                    );
                }

                Cart::where('user_id', $user->id)->delete();
            });
        } catch (Throwable $e) {
            Log::error('Unified cart checkout failed after payment was charged.', [
                'user_id'           => $user->id,
                'payment_method_id' => $payment_method_id,
                'error'             => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'An error occurred while creating your orders. Please contact support.',
                'error'   => $e->getMessage(),
            ], 500);
        }

        // Increment coupon usage counts after the transaction commits
        // (skipped for credits payments since no coupons are permitted)
        if (! $is_credits_payment) {
            foreach ($coupon_models as $coupon) {
                $coupon->increment('times_used');
            }
        }

        // Fire product-specific events
        foreach ($created_orders as $entry) {
            if ($entry['product_type'] === 'link_building') {
                event(new LinkBuildingOrderPlaced($user, $entry['model'], $entry['total_links']));
            }
        }

        // Create invoice(s): one combined invoice for multi-product, one per order for single-product
        if ($session_id && count($created_orders) > 1) {
            $this->invoiceService->createForMultiProductSession(
                $user, $session_id, $session_title, $created_orders, 'Credit Card', 'usd', 0.0
            );
        } else {
            $entry = $created_orders[0];
            match ($entry['product_type']) {
                'link_building' => $this->invoiceService->createForLinkBuildingOrder(
                    $user, $entry['model'], 'Credit Card', 'usd', 0.0, $entry['total_links']
                ),
                'new_content' => $this->invoiceService->createForNewContentOrder(
                    $user, $entry['model'], 'Credit Card', 'usd', 0.0
                ),
                'content_optimization' => $this->invoiceService->createForContentOptimizationOrder(
                    $user, $entry['model'], 'Credit Card', 'usd', 0.0
                ),
                'content_brief' => $this->invoiceService->createForContentBriefOrder(
                    $user, $entry['model'], 'Credit Card', 'usd', 0.0
                ),
                default => null,
            };
        }

        $response_orders = array_map(fn ($entry) => [
            'order_id'     => $entry['order_id'],
            'product_type' => $entry['product_type'],
            'total_amount' => $entry['total_amount'],
        ], $created_orders);

        return response()->json(['data' => [
            'session_id' => $session_id,
            'orders'     => $response_orders,
        ]]);
    }

    private function createLinkBuildingOrder(
        User $user,
        array $items,
        array $billing,
        string $payment_method_id,
        array $coupon_models,
        ?string $order_title,
        ?string $order_notes,
        ?string $session_id,
        ?string $session_title,
        bool $skip_discounts = false
    ): array {
        $total_links = 0;
        $subtotal    = 0.0;

        foreach ($items as $item) {
            $total_links += (int) $item['quantity'];
            $subtotal    += (float) $item['unit_price'] * (int) $item['quantity'];
        }

        $subtotal = round($subtotal, 2);

        // Bulk discounts and coupons are skipped when paying with credits.
        $bulk_discount = (! $skip_discounts && $total_links >= self::BULK_DISCOUNT_THRESHOLD)
            ? round($subtotal * self::BULK_DISCOUNT_RATE, 2)
            : 0.0;

        $amount_after_bulk = round($subtotal - $bulk_discount, 2);

        $applied_coupons = [];
        $current_amount  = $amount_after_bulk;

        if (! $skip_discounts) {
            foreach ($coupon_models as $coupon) {
                $result = $this->couponService->validateAndCalculate(
                    $coupon,
                    $current_amount,
                    $user->id
                );

                if ($result['valid']) {
                    $applied_coupons[] = ['coupon' => $coupon, 'discount_amount' => $result['discount_amount']];
                    $current_amount    = round($current_amount - $result['discount_amount'], 2);
                }
            }
        }

        $total_coupon_discount = array_sum(array_column($applied_coupons, 'discount_amount'));
        $order_total           = round($amount_after_bulk - $total_coupon_discount, 2);

        $order = LinkBuildingOrder::create([
            'user_id'                  => $user->id,
            'order_title'              => $order_title,
            'order_notes'              => $order_notes,
            'subtotal_before_discount' => $subtotal,
            'total_amount'             => $order_total,
            'status'                   => 'pending',
            'payment_intent_id'        => $payment_method_id,
            'session_id'               => $session_id,
            'session_title'            => $session_title,
        ]);

        foreach ($applied_coupons as $entry) {
            $order->orderCoupons()->create([
                'coupon_id'       => $entry['coupon']->id,
                'discount_amount' => $entry['discount_amount'],
            ]);
        }

        foreach ($items as $item_data) {
            $item_subtotal = round((float) $item_data['unit_price'] * (int) $item_data['quantity'], 2);

            $item = $order->items()->create([
                'dr_tier_id' => $item_data['dr_tier_id'],
                'quantity'   => $item_data['quantity'],
                'unit_price' => (float) $item_data['unit_price'],
                'subtotal'   => $item_subtotal,
            ]);

            foreach ($item_data['placements'] as $placement_data) {
                $item->placements()->create([
                    'row_index'    => $placement_data['row_index'],
                    'keyword'      => $placement_data['keyword'] ?: null,
                    'landing_page' => $placement_data['landing_page'] ?: null,
                    'exact_match'  => $placement_data['exact_match'],
                ]);
            }
        }

        $order->billing()->create([
            'company'     => $billing['company'] ?: null,
            'address'     => $billing['address'] ?: null,
            'city'        => $billing['city'] ?: null,
            'state'       => $billing['state'] ?: null,
            'country'     => $billing['country'] ?: null,
            'postal_code' => $billing['postal_code'] ?: null,
        ]);

        return [
            'product_type' => 'link_building',
            'order_id'     => $order->id,
            'total_amount' => $order_total,
            'model'        => $order,
            'total_links'  => $total_links,
        ];
    }

    private function createContentOptimizationOrder(
        User $user,
        array $items,
        array $billing,
        string $payment_method_id,
        array $coupon_models,
        ?string $order_title,
        ?string $order_notes,
        ?string $session_id,
        ?string $session_title,
        bool $skip_discounts = false
    ): array {
        $subtotal = 0.0;

        foreach ($items as $item) {
            $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
        }

        $subtotal = round($subtotal, 2);

        $applied_coupons = [];
        $current_amount  = $subtotal;

        if (! $skip_discounts) {
            foreach ($coupon_models as $coupon) {
                $result = $this->couponService->validateAndCalculate(
                    $coupon,
                    $current_amount,
                    $user->id
                );

                if ($result['valid']) {
                    $applied_coupons[] = ['coupon' => $coupon, 'discount_amount' => $result['discount_amount']];
                    $current_amount    = round($current_amount - $result['discount_amount'], 2);
                }
            }
        }

        $total_coupon_discount = array_sum(array_column($applied_coupons, 'discount_amount'));
        $order_total           = round($subtotal - $total_coupon_discount, 2);

        $order = ContentOptimizationOrder::create([
            'user_id'                  => $user->id,
            'order_title'              => $order_title,
            'order_notes'              => $order_notes,
            'subtotal_before_discount' => $subtotal,
            'total_amount'             => $order_total,
            'status'                   => 'pending',
            'payment_intent_id'        => $payment_method_id,
            'session_id'               => $session_id,
            'session_title'            => $session_title,
        ]);

        foreach ($items as $item_data) {
            $item_subtotal = round((float) $item_data['unit_price'] * (int) $item_data['quantity'], 2);

            $item = $order->items()->create([
                'tier_id'    => $item_data['tier_id'],
                'quantity'   => $item_data['quantity'],
                'unit_price' => (float) $item_data['unit_price'],
                'subtotal'   => $item_subtotal,
            ]);

            foreach ($item_data['intake_rows'] ?? [] as $index => $row) {
                $item->intakeRows()->create([
                    'row_index'          => $index + 1,
                    'primary_keyword'    => $row['primary_keyword'],
                    'secondary_keywords' => $row['secondary_keywords'] ?? null,
                    'content_page_url'   => $row['content_page_url'],
                    'notes'              => $row['notes'] ?? null,
                ]);
            }
        }

        $order->billing()->create([
            'company'     => $billing['company'] ?: null,
            'address'     => $billing['address'] ?: null,
            'city'        => $billing['city'] ?: null,
            'state'       => $billing['state'] ?: null,
            'country'     => $billing['country'] ?: null,
            'postal_code' => $billing['postal_code'] ?: null,
        ]);

        foreach ($applied_coupons as $entry) {
            $order->orderCoupons()->create([
                'coupon_id'       => $entry['coupon']->id,
                'discount_amount' => $entry['discount_amount'],
            ]);
        }

        return [
            'product_type' => 'content_optimization',
            'order_id'     => $order->id,
            'total_amount' => $order_total,
            'model'        => $order,
        ];
    }

    private function createNewContentOrder(
        User $user,
        array $items,
        array $billing,
        string $payment_method_id,
        array $coupon_models,
        ?string $order_title,
        ?string $order_notes,
        ?string $session_id,
        ?string $session_title,
        bool $skip_discounts = false
    ): array {
        $subtotal = 0.0;

        foreach ($items as $item) {
            $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
        }

        $subtotal = round($subtotal, 2);

        $applied_coupons = [];
        $current_amount  = $subtotal;

        if (! $skip_discounts) {
            foreach ($coupon_models as $coupon) {
                $result = $this->couponService->validateAndCalculate(
                    $coupon,
                    $current_amount,
                    $user->id
                );

                if ($result['valid']) {
                    $applied_coupons[] = ['coupon' => $coupon, 'discount_amount' => $result['discount_amount']];
                    $current_amount    = round($current_amount - $result['discount_amount'], 2);
                }
            }
        }

        $total_coupon_discount = array_sum(array_column($applied_coupons, 'discount_amount'));
        $order_total           = round($subtotal - $total_coupon_discount, 2);

        $order = NewContentOrder::create([
            'user_id'                  => $user->id,
            'order_title'              => $order_title,
            'order_notes'              => $order_notes,
            'subtotal_before_discount' => $subtotal,
            'total_amount'             => $order_total,
            'status'                   => 'pending',
            'payment_intent_id'        => $payment_method_id,
            'session_id'               => $session_id,
            'session_title'            => $session_title,
        ]);

        foreach ($items as $item_data) {
            $item_subtotal = round((float) $item_data['unit_price'] * (int) $item_data['quantity'], 2);

            $item = $order->items()->create([
                'tier_id'    => $item_data['tier_id'],
                'quantity'   => $item_data['quantity'],
                'unit_price' => (float) $item_data['unit_price'],
                'subtotal'   => $item_subtotal,
            ]);

            foreach ($item_data['intake_rows'] ?? [] as $index => $row) {
                $item->intakeRows()->create([
                    'row_index'          => $index + 1,
                    'keyword_phrase'     => $row['keyword_phrase'],
                    'secondary_keywords' => $row['secondary_keywords'] ?? null,
                    'type_of_content'    => $row['type_of_content'] ?? null,
                    'notes'              => $row['notes'] ?? null,
                    'status'             => 'pending',
                ]);
            }
        }

        $order->billing()->create([
            'company'     => $billing['company'] ?: null,
            'address'     => $billing['address'] ?: null,
            'city'        => $billing['city'] ?: null,
            'state'       => $billing['state'] ?: null,
            'country'     => $billing['country'] ?: null,
            'postal_code' => $billing['postal_code'] ?: null,
        ]);

        foreach ($applied_coupons as $entry) {
            $order->orderCoupons()->create([
                'coupon_id'       => $entry['coupon']->id,
                'discount_amount' => $entry['discount_amount'],
            ]);
        }

        return [
            'product_type' => 'new_content',
            'order_id'     => $order->id,
            'total_amount' => $order_total,
            'model'        => $order,
        ];
    }

    private function createContentBriefOrder(
        User $user,
        array $items,
        array $billing,
        string $payment_method_id,
        array $coupon_models,
        ?string $order_title,
        ?string $order_notes,
        ?string $session_id,
        ?string $session_title,
        bool $skip_discounts = false
    ): array {
        $subtotal = 0.0;

        foreach ($items as $item) {
            $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
        }

        $subtotal = round($subtotal, 2);

        $applied_coupons = [];
        $current_amount  = $subtotal;

        if (! $skip_discounts) {
            foreach ($coupon_models as $coupon) {
                $result = $this->couponService->validateAndCalculate(
                    $coupon,
                    $current_amount,
                    $user->id
                );

                if ($result['valid']) {
                    $applied_coupons[] = ['coupon' => $coupon, 'discount_amount' => $result['discount_amount']];
                    $current_amount    = round($current_amount - $result['discount_amount'], 2);
                }
            }
        }

        $total_coupon_discount = array_sum(array_column($applied_coupons, 'discount_amount'));
        $order_total           = round($subtotal - $total_coupon_discount, 2);

        $order = ContentBriefOrder::create([
            'user_id'                  => $user->id,
            'order_title'              => $order_title,
            'order_notes'              => $order_notes,
            'subtotal_before_discount' => $subtotal,
            'total_amount'             => $order_total,
            'status'                   => 'pending',
            'payment_intent_id'        => $payment_method_id,
            'session_id'               => $session_id,
            'session_title'            => $session_title,
        ]);

        foreach ($items as $item_data) {
            $item_subtotal = round((float) $item_data['unit_price'] * (int) $item_data['quantity'], 2);

            $item = $order->items()->create([
                'tier_id'    => $item_data['tier_id'],
                'quantity'   => $item_data['quantity'],
                'unit_price' => (float) $item_data['unit_price'],
                'subtotal'   => $item_subtotal,
            ]);

            foreach ($item_data['intake_rows'] ?? [] as $index => $row) {
                $item->intakeRows()->create([
                    'row_index'          => $index + 1,
                    'primary_keyword'    => $row['primary_keyword'],
                    'secondary_keywords' => $row['secondary_keywords'] ?? null,
                    'content_page_url'   => $row['content_page_url'],
                    'notes'              => $row['notes'] ?? null,
                ]);
            }
        }

        $order->billing()->create([
            'company'     => $billing['company'] ?: null,
            'address'     => $billing['address'] ?: null,
            'city'        => $billing['city'] ?: null,
            'state'       => $billing['state'] ?: null,
            'country'     => $billing['country'] ?: null,
            'postal_code' => $billing['postal_code'] ?: null,
        ]);

        foreach ($applied_coupons as $entry) {
            $order->orderCoupons()->create([
                'coupon_id'       => $entry['coupon']->id,
                'discount_amount' => $entry['discount_amount'],
            ]);
        }

        return [
            'product_type' => 'content_brief',
            'order_id'     => $order->id,
            'total_amount' => $order_total,
            'model'        => $order,
        ];
    }
}
