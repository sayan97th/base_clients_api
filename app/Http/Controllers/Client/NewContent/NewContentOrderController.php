<?php

namespace App\Http\Controllers\Client\NewContent;

use App\Http\Controllers\Controller;
use App\Http\Requests\NewContent\StoreNewContentOrderRequest;
use App\Http\Resources\NewContentOrderResource;
use App\Models\Coupon;
use App\Models\NewContentOrder;
use App\Models\NewContentTier;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class NewContentOrderController extends Controller
{
    public function __construct(protected StripeService $stripeService) {}

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $orders = NewContentOrder::where('user_id', $user->id)
            ->withCount('items as items_count')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => NewContentOrderResource::collection($orders)->resolve(),
        ]);
    }

    public function show(string $order_id): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $order = NewContentOrder::where('id', $order_id)
            ->with(['items.tier', 'items.intakeRows'])
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($order->user_id !== $user->id) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        return response()->json(['data' => $this->buildOrderDetail($order)]);
    }

    private function buildOrderDetail(NewContentOrder $order): array
    {
        return [
            'id'           => $order->id,
            'order_title'  => $order->order_title,
            'order_notes'  => $order->order_notes,
            'total_amount' => $order->total_amount,
            'status'       => $order->status,
            'created_at'   => $order->created_at,
            'updated_at'   => $order->updated_at,
            'items'        => $order->items->map(fn ($item) => [
                'id'          => $item->id,
                'tier_id'     => $item->tier_id,
                'quantity'    => $item->quantity,
                'unit_price'  => $item->unit_price,
                'subtotal'    => $item->subtotal,
                'tier'        => $item->tier ? [
                    'id'              => $item->tier->id,
                    'label'           => $item->tier->label,
                    'turnaround_time' => $item->tier->turnaround_time,
                    'price'           => $item->tier->price,
                    'is_active'       => $item->tier->is_active,
                    'is_most_popular' => $item->tier->is_most_popular,
                    'max_quantity'    => $item->tier->max_quantity,
                    'is_hidden'       => $item->tier->is_hidden,
                    'sort_order'      => $item->tier->sort_order,
                    'created_at'      => $item->tier->created_at,
                    'updated_at'      => $item->tier->updated_at,
                ] : null,
                'intake_rows' => $item->intakeRows->map(fn ($row) => [
                    'keyword_phrase'  => $row->keyword_phrase,
                    'type_of_content' => $row->type_of_content,
                    'notes'           => $row->notes,
                ])->values(),
            ])->values(),
        ];
    }

    public function store(StoreNewContentOrderRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        // Fetch active tiers from DB — do not trust frontend prices
        $tier_ids  = collect($request->items)->pluck('tier_id')->unique()->values()->all();
        $tiers_map = NewContentTier::whereIn('id', $tier_ids)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        // Recalculate total server-side using DB prices
        $calculated_total = 0.0;

        foreach ($request->items as $item) {
            $tier              = $tiers_map->get($item['tier_id']);
            $calculated_total += (float) $tier->price * (int) $item['quantity'];
        }

        $calculated_total = round($calculated_total, 2);

        // Apply coupons sequentially
        $coupon_ids      = $request->coupon_ids ?? [];
        $applied_coupons = [];
        $current_amount  = $calculated_total;

        foreach ($coupon_ids as $coupon_id) {
            $coupon = Coupon::find($coupon_id);

            if (!$coupon) {
                return response()->json(['message' => 'One or more coupons are no longer valid.'], 422);
            }

            $applied_coupons[] = ['coupon' => $coupon];
        }

        // Verify frontend total matches server calculation (±$0.01 tolerance)
        if (abs($calculated_total - (float) $request->total_amount) > 0.01) {
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

        $order = DB::transaction(function () use ($request, $user, $tiers_map, $calculated_total, $applied_coupons, $payment_intent_id) {
            $order = NewContentOrder::create([
                'user_id'            => $user->id,
                'order_notes'        => $request->order_notes,
                'total_amount'       => $calculated_total,
                'status'             => 'pending',
                'payment_intent_id'  => $payment_intent_id,
            ]);

            foreach ($request->items as $item_data) {
                $tier     = $tiers_map->get($item_data['tier_id']);
                $subtotal = round((float) $tier->price * (int) $item_data['quantity'], 2);

                $order->items()->create([
                    'tier_id'    => $item_data['tier_id'],
                    'quantity'   => $item_data['quantity'],
                    'unit_price' => (float) $tier->price,
                    'subtotal'   => $subtotal,
                ]);
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
                    'discount_amount' => 0,
                ]);
            }

            return $order;
        });

        return response()->json([
            'data' => [
                'order_id'     => $order->id,
                'status'       => $order->status,
                'total_amount' => $order->total_amount,
                'created_at'   => $order->created_at,
            ],
        ], 201);
    }
}
