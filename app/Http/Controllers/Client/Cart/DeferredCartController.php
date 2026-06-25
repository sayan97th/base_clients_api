<?php

namespace App\Http\Controllers\Client\Cart;

use App\Events\PayLaterOrderPlaced;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\DeferredCheckoutCartRequest;
use App\Jobs\SendAdminPayLaterOrderNotificationJob;
use App\Models\ContentBriefOrder;
use App\Models\ContentOptimizationOrder;
use App\Models\Coupon;
use App\Models\DrTier;
use App\Models\Invoice;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderPlacement;
use App\Models\NewContentOrder;
use App\Models\User;
use App\Services\CouponService;
use App\Services\EmailNotificationSettingService;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DeferredCartController extends Controller
{
    private const BULK_DISCOUNT_THRESHOLD = 10;
    private const BULK_DISCOUNT_RATE      = 0.10;

    public function __construct(
        protected CouponService $couponService,
        protected InvoiceService $invoiceService,
    ) {}

    /**
     * POST /api/cart/checkout/deferred
     *
     * Creates orders without charging the client. Each order is set to
     * payment_pending, and a single unpaid invoice is generated covering
     * all orders in the session.
     */
    public function checkout(DeferredCheckoutCartRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $coupon_ids    = $request->input('coupon_ids', []);
        $coupon_models = [];

        foreach ($coupon_ids as $coupon_id) {
            $coupon = Coupon::find($coupon_id);

            if (! $coupon) {
                return response()->json(['message' => 'One or more coupons are no longer valid.'], 422);
            }

            $coupon_models[$coupon_id] = $coupon;
        }

        $order_title   = $request->input('order_title');
        $order_notes   = $request->input('order_notes');
        $session_id    = $request->input('session_id') ?? (string) Str::uuid();
        $session_title = $order_title;
        $created_orders = [];

        $link_building_items        = $request->input('link_building_items');
        $content_optimization_items = $request->input('content_optimization_items');
        $new_content_items          = $request->input('new_content_items');
        $content_brief_items        = $request->input('content_brief_items');

        try {
            DB::transaction(function () use (
                $user, $order_title, $order_notes,
                $coupon_models, $session_id, $session_title,
                $link_building_items, $content_optimization_items,
                $new_content_items, $content_brief_items,
                &$created_orders
            ) {
                if (! empty($link_building_items)) {
                    $created_orders[] = $this->createLinkBuildingOrder(
                        $user, $link_building_items, $coupon_models,
                        $order_title, $order_notes, $session_id, $session_title
                    );
                }

                if (! empty($content_optimization_items)) {
                    $created_orders[] = $this->createContentOptimizationOrder(
                        $user, $content_optimization_items, $coupon_models,
                        $order_title, $order_notes, $session_id, $session_title
                    );
                }

                if (! empty($new_content_items)) {
                    $created_orders[] = $this->createNewContentOrder(
                        $user, $new_content_items, $coupon_models,
                        $order_title, $order_notes, $session_id, $session_title
                    );
                }

                if (! empty($content_brief_items)) {
                    $created_orders[] = $this->createContentBriefOrder(
                        $user, $content_brief_items, $coupon_models,
                        $order_title, $order_notes, $session_id, $session_title
                    );
                }
            });
        } catch (Throwable $e) {
            Log::error('Deferred cart checkout failed.', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'An error occurred while creating your orders. Please contact support.',
                'error'   => $e->getMessage(),
            ], 500);
        }

        foreach ($coupon_models as $coupon) {
            $coupon->increment('times_used');
        }

        $invoice = null;

        if ($session_id && count($created_orders) > 1) {
            $invoice = $this->invoiceService->createForMultiProductSession(
                $user,
                $session_id,
                $session_title,
                $created_orders,
                'Pending',
                'usd',
                0.0,
                'unpaid',
                InvoiceService::DEFERRED_PAYMENT_DUE_DAYS
            );
        } else {
            $entry   = $created_orders[0];
            $invoice = match ($entry['product_type']) {
                'link_building' => $this->invoiceService->createForLinkBuildingOrder(
                    $user,
                    $entry['model'],
                    'Pending',
                    'usd',
                    0.0,
                    $entry['total_links'],
                    'unpaid',
                    InvoiceService::DEFERRED_PAYMENT_DUE_DAYS
                ),
                'new_content' => $this->invoiceService->createForNewContentOrder(
                    $user,
                    $entry['model'],
                    'Pending',
                    'usd',
                    0.0,
                    'unpaid',
                    InvoiceService::DEFERRED_PAYMENT_DUE_DAYS
                ),
                'content_optimization' => $this->invoiceService->createForContentOptimizationOrder(
                    $user,
                    $entry['model'],
                    'Pending',
                    'usd',
                    0.0,
                    'unpaid',
                    InvoiceService::DEFERRED_PAYMENT_DUE_DAYS
                ),
                'content_brief' => $this->invoiceService->createForContentBriefOrder(
                    $user,
                    $entry['model'],
                    'Pending',
                    'usd',
                    0.0,
                    'unpaid',
                    InvoiceService::DEFERRED_PAYMENT_DUE_DAYS
                ),
                default => null,
            };
        }

        // Dispatch admin notifications — non-critical, errors are logged but never fail the response
        if ($invoice) {
            $this->dispatchDeferredCheckoutNotifications($invoice, $user);
        }

        $response_orders = array_map(fn ($entry) => [
            'order_id'     => $entry['order_id'],
            'product_type' => $entry['product_type'],
            'total_amount' => $entry['total_amount'],
        ], $created_orders);

        return response()->json(['data' => [
            'session_id'        => $session_id,
            'orders'            => $response_orders,
            'invoice_unique_id' => $invoice?->unique_id,
        ]]);
    }

    /**
     * Dispatch all post-deferred-checkout admin notifications.
     * Non-critical: failures are logged but never fail the response.
     */
    private function dispatchDeferredCheckoutNotifications(Invoice $invoice, User $user): void
    {
        // Admin email notifications to all recipients in Email Notification Settings
        try {
            SendAdminPayLaterOrderNotificationJob::dispatch($invoice->id);
        } catch (Throwable $e) {
            Log::warning('Failed to dispatch admin pay-later order notification job after deferred checkout.', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);
        }

        // Admin in-app notifications to all configured admin recipients
        try {
            $payer_name  = $user->full_name ?? $user->email;
            $admin_link  = '/admin/invoices/' . $invoice->id;
            $recipients  = EmailNotificationSettingService::resolveAdminRecipients();
            $admin_users = User::whereIn('email', array_column($recipients, 'email'))
                ->where('is_active', true)
                ->get();

            foreach ($admin_users as $admin) {
                event(new PayLaterOrderPlaced(
                    user:           $admin,
                    client_name:    $payer_name,
                    amount:         (float) $invoice->total_amount,
                    invoice_number: $invoice->invoice_number,
                    link:           $admin_link,
                    invoice:        $invoice,
                ));
            }
        } catch (Throwable $e) {
            Log::warning('Failed to dispatch admin in-app pay-later order notifications after deferred checkout.', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function createLinkBuildingOrder(
        User $user,
        array $items,
        array $coupon_models,
        ?string $order_title,
        ?string $order_notes,
        ?string $session_id,
        ?string $session_title
    ): array {
        $total_links = 0;
        $subtotal    = 0.0;

        foreach ($items as $item) {
            $total_links += (int) $item['quantity'];
            $subtotal    += (float) $item['unit_price'] * (int) $item['quantity'];
        }

        $subtotal = round($subtotal, 2);

        // Only one discount type applies — whichever saves more.
        $potential_bulk = $total_links >= self::BULK_DISCOUNT_THRESHOLD
            ? round($subtotal * self::BULK_DISCOUNT_RATE, 2)
            : 0.0;

        // Calculate coupon discount on the full subtotal (not post-bulk)
        $potential_coupons       = [];
        $potential_coupon_amount = 0.0;
        $temp_amount             = $subtotal;

        foreach ($coupon_models as $coupon) {
            $result = $this->couponService->validateAndCalculate($coupon, $temp_amount, $user->id);

            if ($result['valid']) {
                $potential_coupons[]     = ['coupon' => $coupon, 'discount_amount' => $result['discount_amount']];
                $potential_coupon_amount += $result['discount_amount'];
                $temp_amount             = round($temp_amount - $result['discount_amount'], 2);
            }
        }

        // Coupon wins when it saves more than the bulk discount
        if ($potential_coupon_amount > 0 && $potential_coupon_amount >= $potential_bulk) {
            $bulk_discount   = 0.0;
            $applied_coupons = $potential_coupons;
        } else {
            $bulk_discount   = $potential_bulk;
            $applied_coupons = [];
        }

        $total_discount = $bulk_discount + array_sum(array_column($applied_coupons, 'discount_amount'));
        $order_total    = max(0.0, round($subtotal - $total_discount, 2));

        $order = LinkBuildingOrder::create([
            'user_id'                  => $user->id,
            'order_title'              => $order_title,
            'order_notes'              => $order_notes,
            'subtotal_before_discount' => $subtotal,
            'total_amount'             => $order_total,
            'status'                   => 'payment_pending',
            'payment_intent_id'        => null,
            'session_id'               => $session_id,
            'session_title'            => $session_title,
        ]);

        foreach ($applied_coupons as $entry) {
            $order->orderCoupons()->create([
                'coupon_id'       => $entry['coupon']->id,
                'discount_amount' => $entry['discount_amount'],
            ]);
        }

        // Reserve starting sequence number for BL- order IDs once per checkout call
        // so all placements in this order receive consecutive identifiers without
        // issuing a separate MAX query for every individual row.
        $max_bl_num  = LinkBuildingOrderPlacement::whereNotNull('order_id')
            ->whereRaw("order_id REGEXP '^BL-[0-9]+$'")
            ->selectRaw('MAX(CAST(SUBSTRING(order_id, 4) AS UNSIGNED)) as max_num')
            ->value('max_num');
        $next_bl_num = ($max_bl_num === null ? 0 : (int) $max_bl_num) + 1;

        foreach ($items as $item_data) {
            $item_subtotal = round((float) $item_data['unit_price'] * (int) $item_data['quantity'], 2);

            $item = $order->items()->create([
                'dr_tier_id' => $item_data['dr_tier_id'],
                'quantity'   => $item_data['quantity'],
                'unit_price' => (float) $item_data['unit_price'],
                'subtotal'   => $item_subtotal,
            ]);

            $client_company = trim($user->company ?? '');
            $dr_tier        = DrTier::find($item_data['dr_tier_id']);
            $link_type      = $dr_tier ? $dr_tier->label . ' External' : null;

            foreach ($item_data['placements'] as $placement_data) {
                $item->placements()->create([
                    'order_id'     => 'BL-' . $next_bl_num++,
                    'row_index'    => $placement_data['row_index'],
                    'keyword'      => $placement_data['keyword'] ?: null,
                    'landing_page' => $placement_data['landing_page'] ?: null,
                    'exact_match'  => $placement_data['exact_match'],
                    'client'       => $client_company ?: null,
                    'link_type'    => $link_type,
                    'status'       => 'New Request',
                    'request_date' => now()->format('m/d/Y'),
                    'user_id'      => $user->id,
                ]);
            }
        }

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
        array $coupon_models,
        ?string $order_title,
        ?string $order_notes,
        ?string $session_id,
        ?string $session_title
    ): array {
        $subtotal = 0.0;

        foreach ($items as $item) {
            $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
        }

        $subtotal = round($subtotal, 2);

        $applied_coupons = [];
        $current_amount  = $subtotal;

        foreach ($coupon_models as $coupon) {
            $result = $this->couponService->validateAndCalculate($coupon, $current_amount, $user->id);

            if ($result['valid']) {
                $applied_coupons[] = ['coupon' => $coupon, 'discount_amount' => $result['discount_amount']];
                $current_amount    = round($current_amount - $result['discount_amount'], 2);
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
            'status'                   => 'payment_pending',
            'payment_intent_id'        => null,
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
        array $coupon_models,
        ?string $order_title,
        ?string $order_notes,
        ?string $session_id,
        ?string $session_title
    ): array {
        $subtotal = 0.0;

        foreach ($items as $item) {
            $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
        }

        $subtotal = round($subtotal, 2);

        $applied_coupons = [];
        $current_amount  = $subtotal;

        foreach ($coupon_models as $coupon) {
            $result = $this->couponService->validateAndCalculate($coupon, $current_amount, $user->id);

            if ($result['valid']) {
                $applied_coupons[] = ['coupon' => $coupon, 'discount_amount' => $result['discount_amount']];
                $current_amount    = round($current_amount - $result['discount_amount'], 2);
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
            'status'                   => 'payment_pending',
            'payment_intent_id'        => null,
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
        array $coupon_models,
        ?string $order_title,
        ?string $order_notes,
        ?string $session_id,
        ?string $session_title
    ): array {
        $subtotal = 0.0;

        foreach ($items as $item) {
            $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
        }

        $subtotal = round($subtotal, 2);

        $applied_coupons = [];
        $current_amount  = $subtotal;

        foreach ($coupon_models as $coupon) {
            $result = $this->couponService->validateAndCalculate($coupon, $current_amount, $user->id);

            if ($result['valid']) {
                $applied_coupons[] = ['coupon' => $coupon, 'discount_amount' => $result['discount_amount']];
                $current_amount    = round($current_amount - $result['discount_amount'], 2);
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
            'status'                   => 'payment_pending',
            'payment_intent_id'        => null,
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
