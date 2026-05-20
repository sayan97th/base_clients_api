<?php

namespace App\Http\Controllers\Client\ContentBrief;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContentBrief\StoreContentBriefOrderRequest;
use App\Http\Resources\ContentBriefOrderResource;
use App\Models\ContentBriefOrder;
use App\Models\ContentBriefTier;
use App\Models\Coupon;
use App\Models\User;
use App\Services\CouponService;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContentBriefOrderController extends Controller
{
    public function __construct(
        protected StripeService $stripeService,
        protected CouponService $couponService
    ) {}

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $orders = ContentBriefOrder::where('user_id', $user->id)
            ->withCount('items as items_count')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => ContentBriefOrderResource::collection($orders)->resolve(),
        ]);
    }

    public function store(StoreContentBriefOrderRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        // Fetch active tiers from DB — do not trust frontend prices
        $tier_ids  = collect($request->items)->pluck('tier_id')->unique()->values()->all();
        $tiers_map = ContentBriefTier::whereIn('id', $tier_ids)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        foreach ($tier_ids as $tier_id) {
            if (!$tiers_map->has($tier_id)) {
                return response()->json([
                    'message' => 'One or more selected tiers are not available.',
                    'errors'  => ['items' => ['One or more selected tiers are not available.']],
                ], 422);
            }
        }

        // Recalculate total server-side using DB prices
        $calculated_total = 0.0;

        foreach ($request->items as $item) {
            $tier              = $tiers_map->get($item['tier_id']);
            $calculated_total += (float) $tier->price * (int) $item['quantity'];
        }

        $calculated_total = round($calculated_total, 2);

        // Apply coupons sequentially, each reducing the running amount
        $coupon_ids      = $request->coupon_ids ?? [];
        $applied_coupons = [];
        $current_amount  = $calculated_total;

        foreach ($coupon_ids as $coupon_id) {
            $coupon = Coupon::find($coupon_id);

            if (!$coupon) {
                return response()->json(['message' => 'One or more coupons are no longer valid.'], 422);
            }

            $result = $this->couponService->validateAndCalculate(
                $coupon,
                $current_amount,
                $user->id
            );

            if (!$result['valid']) {
                return response()->json(['message' => 'One or more coupons are no longer valid.'], 422);
            }

            $applied_coupons[] = ['coupon' => $coupon, 'discount_amount' => $result['discount_amount']];
            $current_amount    = round($current_amount - $result['discount_amount'], 2);
        }

        $total_coupon_discount = array_sum(array_column($applied_coupons, 'discount_amount'));
        $final_total           = round($calculated_total - $total_coupon_discount, 2);

        // Verify frontend total matches server calculation (±$0.01 tolerance)
        if (abs($final_total - (float) $request->total_amount) > 0.01) {
            return response()->json([
                'message' => 'Order total does not match the expected amount.',
                'errors'  => ['total_amount' => ['The submitted total does not match the calculated order total.']],
            ], 422);
        }

        // Verify Stripe PaymentIntent before writing anything to DB
        $payment_intent_id = $request->payment['payment_method_id'];
        $stripe_result     = $this->stripeService->verifyPaymentIntent($payment_intent_id);

        if (!$stripe_result['verified']) {
            return response()->json([
                'message' => 'Payment verification failed. The payment was not completed successfully.',
                'errors'  => ['payment.payment_method_id' => ['The provided payment could not be verified.']],
            ], 422);
        }

        $order = DB::transaction(function () use ($request, $user, $tiers_map, $final_total, $applied_coupons, $payment_intent_id) {
            $order = ContentBriefOrder::create([
                'user_id'           => $user->id,
                'order_notes'       => $request->order_notes ?? null,
                'total_amount'      => $final_total,
                'status'            => 'pending',
                'payment_intent_id' => $payment_intent_id,
            ]);

            foreach ($request->items as $item_data) {
                $tier     = $tiers_map->get($item_data['tier_id']);
                $subtotal = round((float) $tier->price * (int) $item_data['quantity'], 2);

                $order_item = $order->items()->create([
                    'tier_id'    => $item_data['tier_id'],
                    'quantity'   => $item_data['quantity'],
                    'unit_price' => (float) $tier->price,
                    'subtotal'   => $subtotal,
                ]);

                foreach ($item_data['intake_rows'] ?? [] as $row_index => $row) {
                    $order_item->intakeRows()->create([
                        'row_index'          => $row_index,
                        'primary_keyword'    => $row['primary_keyword'],
                        'secondary_keywords' => $row['secondary_keywords'] ?? null,
                        'content_page_url'   => $row['content_page_url'],
                        'notes'              => $row['notes'] ?? null,
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

            foreach ($applied_coupons as $entry) {
                $order->orderCoupons()->create([
                    'coupon_id'       => $entry['coupon']->id,
                    'discount_amount' => $entry['discount_amount'],
                ]);
            }

            return $order;
        });

        foreach ($applied_coupons as $entry) {
            $entry['coupon']->increment('times_used');
        }

        return response()->json([
            'data' => [
                'order_id'     => $order->id,
                'status'       => $order->status,
                'total_amount' => $order->total_amount,
                'created_at'   => $order->created_at,
            ],
        ], 201);
    }

    public function show(string $order_id): JsonResponse
    {
        if (!Str::isUuid($order_id)) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        /** @var User $user */
        $user = auth()->user();

        $order = ContentBriefOrder::where('id', $order_id)
            ->with(['items.tier', 'items.intakeRows', 'orderCoupons.coupon', 'invoice'])
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->user_id !== $user->id) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        return response()->json(['data' => $this->buildOrderDetail($order)]);
    }

    private function buildOrderDetail(ContentBriefOrder $order): array
    {
        $subtotal_before_discount = (float) ($order->subtotal_before_discount ?? $order->items->sum('subtotal'));

        return [
            'id'                       => $order->id,
            'order_title'              => $order->order_title,
            'order_notes'              => $order->order_notes,
            'subtotal_before_discount' => round($subtotal_before_discount, 2),
            'total_amount'             => $order->total_amount,
            'credit_amount'            => (float) ($order->invoice?->credit_amount ?? 0),
            'status'                   => $order->status,
            'created_at'               => $order->created_at,
            'updated_at'               => $order->updated_at,
            'items'        => $order->items->map(fn ($item) => [
                'id'             => $item->id,
                'tier_id'        => $item->tier_id,
                'quantity'       => $item->quantity,
                'unit_price'     => $item->unit_price,
                'subtotal'       => $item->subtotal,
                'tier'           => $item->tier ? [
                    'id'              => $item->tier->id,
                    'label'           => $item->tier->label,
                    'turnaround_days' => $item->tier->turnaround_days,
                    'price'           => $item->tier->price,
                    'is_active'       => $item->tier->is_active,
                    'is_most_popular' => $item->tier->is_most_popular,
                    'max_quantity'    => $item->tier->max_quantity,
                    'is_hidden'       => $item->tier->is_hidden,
                    'sort_order'      => $item->tier->sort_order,
                    'created_at'      => $item->tier->created_at,
                    'updated_at'      => $item->tier->updated_at,
                ] : null,
                'co_intake_rows' => $item->relationLoaded('intakeRows')
                    ? $item->intakeRows->map(fn ($row) => [
                        'primary_keyword'    => $row->primary_keyword,
                        'secondary_keywords' => $row->secondary_keywords,
                        'content_page_url'   => $row->content_page_url,
                        'notes'              => $row->notes,
                    ])->values()
                    : [],
            ])->values(),
            'coupons' => $order->relationLoaded('orderCoupons')
                ? $order->orderCoupons->map(fn ($oc) => [
                    'coupon_id'       => $oc->coupon_id,
                    'code'            => $oc->coupon?->code ?? '',
                    'name'            => $oc->coupon?->name ?? '',
                    'discount_type'   => $oc->coupon?->discount_type ?? 'percentage',
                    'discount_value'  => $oc->coupon?->discount_value ?? 0,
                    'discount_amount' => round((float) $oc->discount_amount, 2),
                ])->values()
                : [],
        ];
    }
}
