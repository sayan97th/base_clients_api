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
            ->with(['updates' => function ($query) {
                $query->latest()->limit(1);
            }])
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
                'last_update_at' => $order->updates->first()?->created_at,
            ]);

        return response()->json(['data' => $orders]);
    }

    public function store(StoreLinkBuildingOrderRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        // Fetch DR tier prices from DB — do not trust frontend prices
        $dr_tier_ids  = collect($request->items)->pluck('dr_tier_id')->unique()->values()->all();
        $dr_tiers_map = DrTier::whereIn('id', $dr_tier_ids)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        foreach ($dr_tier_ids as $tier_id) {
            if (!$dr_tiers_map->has($tier_id)) {
                return response()->json([
                    'message' => 'One or more selected DR tiers are not available.',
                    'errors'  => ['items' => ['One or more selected DR tiers are not available.']],
                ], 422);
            }
        }

        // Recalculate subtotal using DB prices
        $total_links = 0;
        $subtotal    = 0.0;

        foreach ($request->items as $item) {
            $tier         = $dr_tiers_map->get($item['dr_tier_id']);
            $total_links += $item['quantity'];
            $subtotal    += $tier->price_per_link * $item['quantity'];
        }

        $subtotal = round($subtotal, 2);

        $discount_applied     = $total_links >= self::BULK_DISCOUNT_THRESHOLD;
        $bulk_discount_amount = $discount_applied ? round($subtotal * self::BULK_DISCOUNT_RATE, 2) : 0;
        $amount_after_bulk    = round($subtotal - $bulk_discount_amount, 2);

        $coupon                 = null;
        $coupon_discount_amount = 0;

        if ($request->coupon_id) {
            $coupon = Coupon::find($request->coupon_id);

            if (!$coupon) {
                return response()->json(['message' => 'The coupon is no longer valid.'], 422);
            }

            $dr_tier_amounts = collect($request->items)
                ->groupBy('dr_tier_id')
                ->map(fn ($group) => round(
                    $group->sum(fn ($i) => $dr_tiers_map->get($i['dr_tier_id'])->price_per_link * $i['quantity']),
                    2
                ))
                ->all();

            $result = $this->couponService->validateAndCalculate(
                $coupon,
                $amount_after_bulk,
                $user->id,
                $dr_tier_ids,
                $dr_tier_amounts
            );

            if (!$result['valid']) {
                return response()->json(['message' => 'The coupon is no longer valid.'], 422);
            }

            $coupon_discount_amount = $result['discount_amount'];
        }

        $calculated_total = round($amount_after_bulk - $coupon_discount_amount, 2);

        // Verify total matches frontend-submitted amount (±$0.01 tolerance for rounding)
        if (abs($calculated_total - (float) $request->total_amount) > 0.01) {
            return response()->json([
                'message' => 'Order total does not match the expected amount.',
                'errors'  => ['total_amount' => ['The submitted total does not match the calculated order total.']],
            ], 422);
        }

        // Verify PaymentIntent with Stripe before persisting anything
        $payment_intent_id = $request->payment['payment_method_id'];
        $stripe_result     = $this->stripeService->verifyPaymentIntent($payment_intent_id);

        if (!$stripe_result['verified']) {
            return response()->json([
                'message' => 'Payment verification failed. The payment was not completed successfully.',
                'errors'  => ['payment.payment_method_id' => ['The provided payment could not be verified.']],
            ], 422);
        }

        $order = DB::transaction(function () use ($request, $user, $calculated_total, $coupon, $coupon_discount_amount, $payment_intent_id, $dr_tiers_map) {
            $order = LinkBuildingOrder::create([
                'user_id'                => $user->id,
                'order_title'            => $request->order_title,
                'order_notes'            => $request->order_notes,
                'total_amount'           => $calculated_total,
                'status'                 => 'pending',
                'payment_intent_id'      => $payment_intent_id,
                'coupon_id'              => $coupon?->id,
                'coupon_discount_amount' => $coupon_discount_amount > 0 ? $coupon_discount_amount : null,
            ]);

            foreach ($request->items as $item_data) {
                $tier     = $dr_tiers_map->get($item_data['dr_tier_id']);
                $subtotal = round($tier->price_per_link * $item_data['quantity'], 2);

                $item = $order->items()->create([
                    'dr_tier_id' => $item_data['dr_tier_id'],
                    'quantity'   => $item_data['quantity'],
                    'unit_price' => $tier->price_per_link,
                    'subtotal'   => $subtotal,
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
                'company'     => $request->billing['company'] ?: null,
                'address'     => $request->billing['address'] ?: null,
                'city'        => $request->billing['city'] ?: null,
                'state'       => $request->billing['state'] ?: null,
                'country'     => $request->billing['country'] ?: null,
                'postal_code' => $request->billing['postal_code'] ?: null,
            ]);

            return $order;
        });

        if ($coupon) {
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
        $user = auth()->user();

        $order = LinkBuildingOrder::where('id', $id)
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

        if ($order->user_id !== $user->id) {
            return response()->json(['message' => 'You do not have permission to view this order.'], 403);
        }

        return response()->json(['data' => $this->buildOrderDetail($order)]);
    }

    private function buildOrderDetail(LinkBuildingOrder $order): array
    {
        return [
            'id'                => $order->id,
            'order_title'       => $order->order_title,
            'order_notes'       => $order->order_notes,
            'total_amount'      => $order->total_amount,
            'status'            => $order->status,
            'payment_intent_id' => $order->payment_intent_id,
            'created_at'        => $order->created_at,
            'updated_at'        => $order->updated_at,
            'items'             => $order->items->map(fn ($item) => [
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
            'billing' => $order->billing ? [
                'id'          => $order->billing->id,
                'company'     => $order->billing->company,
                'address'     => $order->billing->address,
                'city'        => $order->billing->city,
                'state'       => $order->billing->state,
                'country'     => $order->billing->country,
                'postal_code' => $order->billing->postal_code,
            ] : null,
        ];
    }
}
