<?php

namespace App\Http\Controllers\Client\LinkBuilding;

use App\Events\LinkBuildingOrderPlaced;
use App\Http\Controllers\Controller;
use App\Http\Requests\LinkBuilding\StoreLinkBuildingOrderRequest;
use App\Models\Coupon;
use App\Models\DrTier;
use App\Models\LinkBuildingOrder;
use App\Models\User;
use App\Services\CouponService;
use App\Services\InvoiceService;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    private const BULK_DISCOUNT_THRESHOLD = 10;
    private const BULK_DISCOUNT_RATE      = 0.10;

    public function __construct(
        protected InvoiceService $invoiceService,
        protected CouponService $couponService,
        protected StripeService $stripeService
    ) {}

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $orders = LinkBuildingOrder::where('user_id', $user->id)
            ->where('is_hidden', false)
            ->withCount(['items as items_count' => function ($query) {
                $query->selectRaw('sum(quantity)');
            }])
            ->withCount('updates as updates_count')
            ->withMax('updates as last_update_at', 'created_at')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($order) => [
                'id'             => $order->id,
                'order_title'    => $order->order_title,
                'total_amount'   => $order->total_amount,
                'status'         => $order->status,
                'created_at'     => $order->created_at,
                'items_count'    => (int) ($order->items_count ?? 0),
                'updates_count'  => (int) ($order->updates_count ?? 0),
                'last_update_at' => $order->last_update_at,
            ]);

        return response()->json(['data' => $orders]);
    }

    public function store(StoreLinkBuildingOrderRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        // 1. Load and verify DR tier prices against the database
        $tier_ids = collect($request->items)->pluck('dr_tier_id')->unique()->values()->all();
        $tiers    = DrTier::whereIn('id', $tier_ids)->where('is_active', true)->get()->keyBy('id');

        foreach ($request->items as $item_data) {
            $tier = $tiers->get($item_data['dr_tier_id']);

            if (!$tier) {
                return response()->json([
                    'message' => "DR tier '{$item_data['dr_tier_id']}' is not available.",
                ], 422);
            }

            if (abs((float) $item_data['unit_price'] - (float) $tier->price_per_link) > 0.001) {
                return response()->json([
                    'message' => "Price mismatch for DR tier '{$tier->dr_label}'. Expected {$tier->price_per_link}.",
                ], 422);
            }

            if (count($item_data['placements']) !== (int) $item_data['quantity']) {
                return response()->json([
                    'message' => "Placement count does not match quantity for DR tier '{$tier->dr_label}'.",
                ], 422);
            }
        }

        // 2. Calculate subtotal and bulk discount
        $total_links          = collect($request->items)->sum('quantity');
        $subtotal             = collect($request->items)->sum(fn ($item) => $item['unit_price'] * $item['quantity']);
        $bulk_discount_amount = $total_links >= self::BULK_DISCOUNT_THRESHOLD
            ? round($subtotal * self::BULK_DISCOUNT_RATE, 2)
            : 0;
        $amount_after_bulk    = round($subtotal - $bulk_discount_amount, 2);

        // 3. Collect and validate coupons (supports both coupon_id and coupon_ids)
        $raw_coupon_ids = array_values(array_filter(array_unique(array_merge(
            $request->coupon_id ? [$request->coupon_id] : [],
            $request->coupon_ids ?? []
        ))));

        $applied_coupons        = collect();
        $coupon_discount_amount = 0;

        if (!empty($raw_coupon_ids)) {
            $coupons = Coupon::whereIn('id', $raw_coupon_ids)->get()->keyBy('id');

            $dr_tier_ids     = collect($request->items)->pluck('dr_tier_id')->unique()->values()->all();
            $dr_tier_amounts = collect($request->items)
                ->groupBy('dr_tier_id')
                ->map(fn ($group) => round($group->sum(fn ($i) => $i['unit_price'] * $i['quantity']), 2))
                ->all();

            $running_amount = $amount_after_bulk;

            foreach ($raw_coupon_ids as $coupon_id) {
                $coupon = $coupons->get($coupon_id);

                if (!$coupon) {
                    return response()->json(['message' => 'One or more coupons could not be found.'], 404);
                }

                $result = $this->couponService->validateAndCalculate(
                    $coupon,
                    $running_amount,
                    $user->id,
                    $dr_tier_ids,
                    $dr_tier_amounts
                );

                if (!$result['valid']) {
                    return response()->json(['message' => $result['message'] ?? 'One of the coupons is no longer valid.'], 422);
                }

                $applied_coupons->push($coupon);
                $coupon_discount_amount += $result['discount_amount'];
                $running_amount          = max(0, round($running_amount - $result['discount_amount'], 2));
            }
        }

        $total_amount = max(0, round($amount_after_bulk - $coupon_discount_amount, 2));

        // 4. Verify submitted total_amount matches server-calculated value (within $0.01 tolerance)
        if (abs($total_amount - (float) $request->total_amount) > 0.01) {
            return response()->json([
                'message' => 'Order total mismatch. Please refresh your cart and try again.',
            ], 422);
        }

        // 5. Process Stripe payment before persisting the order
        $payment_result = $this->stripeService->createPaymentIntent(
            $total_amount,
            $request->payment['payment_method_id'],
            [
                'user_id'     => (string) $user->id,
                'order_title' => $request->order_title ?? '',
            ]
        );

        if (!$payment_result['success']) {
            return response()->json([
                'message' => $payment_result['message'] ?? 'Payment failed. Please check your card details.',
            ], 402);
        }

        $payment_intent_id = $payment_result['payment_intent_id'];

        // 6. Persist order, items, placements, and billing atomically
        $primary_coupon = $applied_coupons->first();

        $order = DB::transaction(function () use (
            $request, $user, $total_amount, $primary_coupon,
            $coupon_discount_amount, $payment_intent_id
        ) {
            $order = LinkBuildingOrder::create([
                'user_id'                => $user->id,
                'order_title'            => $request->order_title,
                'order_notes'            => $request->order_notes,
                'total_amount'           => $total_amount,
                'status'                 => 'pending',
                'payment_intent_id'      => $payment_intent_id,
                'coupon_id'              => $primary_coupon?->id,
                'coupon_discount_amount' => $coupon_discount_amount > 0 ? $coupon_discount_amount : null,
            ]);

            foreach ($request->items as $item_data) {
                $item_subtotal = round($item_data['unit_price'] * $item_data['quantity'], 2);

                $item = $order->items()->create([
                    'dr_tier_id' => $item_data['dr_tier_id'],
                    'quantity'   => $item_data['quantity'],
                    'unit_price' => $item_data['unit_price'],
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
                'company'     => $request->billing['company'] ?? null,
                'address'     => $request->billing['address'],
                'city'        => $request->billing['city'],
                'state'       => $request->billing['state'],
                'country'     => $request->billing['country'],
                'postal_code' => $request->billing['postal_code'],
            ]);

            return $order;
        });

        // 7. Increment usage counter for each applied coupon
        foreach ($applied_coupons as $coupon) {
            $coupon->increment('times_used');
        }

        $this->invoiceService->createForLinkBuildingOrder($user, $order);

        event(new LinkBuildingOrderPlaced($user, $order, $total_links));

        return response()->json([
            'data' => [
                'order_id'     => $order->id,
                'status'       => $order->status,
                'total_amount' => $order->total_amount,
                'created_at'   => $order->created_at,
            ],
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $order = LinkBuildingOrder::where('id', $id)
            ->where('user_id', $user->id)
            ->where('is_hidden', false)
            ->with([
                'items.drTier',
                'items.placements',
                'billing',
            ])
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json(['data' => $this->buildOrderDetail($order)]);
    }

    private function buildOrderDetail(LinkBuildingOrder $order): array
    {
        return [
            'id'                 => $order->id,
            'order_title'        => $order->order_title,
            'order_notes'        => $order->order_notes,
            'total_amount'       => $order->total_amount,
            'status'             => $order->status,
            'payment_intent_id'  => $order->payment_intent_id,
            'created_at'         => $order->created_at,
            'updated_at'         => $order->updated_at,
            'billing'            => $order->billing ? [
                'id'          => $order->billing->id,
                'company'     => $order->billing->company,
                'address'     => $order->billing->address,
                'city'        => $order->billing->city,
                'state'       => $order->billing->state,
                'country'     => $order->billing->country,
                'postal_code' => $order->billing->postal_code,
            ] : null,
            'items' => $order->items->map(fn ($item) => [
                'id'         => $item->id,
                'dr_tier_id' => $item->dr_tier_id,
                'quantity'   => $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal'   => $item->subtotal,
                'dr_tier'    => $item->drTier ? [
                    'id'              => $item->drTier->id,
                    'dr_label'        => $item->drTier->dr_label,
                    'traffic_range'   => $item->drTier->traffic_range,
                    'word_count'      => $item->drTier->word_count,
                    'price_per_link'  => $item->drTier->price_per_link,
                    'is_most_popular' => $item->drTier->is_most_popular,
                    'is_active'       => $item->drTier->is_active,
                ] : null,
                'placements' => $item->placements->map(fn ($placement) => [
                    'id'           => $placement->id,
                    'row_index'    => $placement->row_index,
                    'keyword'      => $placement->keyword,
                    'landing_page' => $placement->landing_page,
                    'exact_match'  => $placement->exact_match,
                ]),
            ]),
        ];
    }
}
