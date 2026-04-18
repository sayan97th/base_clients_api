<?php

namespace App\Http\Controllers\Client\PremiumMentions;

use App\Http\Controllers\Controller;
use App\Http\Requests\PremiumMentions\StorePremiumMentionsOrderRequest;
use App\Models\Coupon;
use App\Models\PremiumMentionsPlan;
use App\Models\PremiumMentionsOrder;
use App\Models\User;
use App\Services\CouponService;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(
        protected CouponService $couponService,
        protected StripeService $stripeService
    ) {}

    public function store(StorePremiumMentionsOrderRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $plan = PremiumMentionsPlan::where('id', $request->plan_id)
            ->where('is_active', true)
            ->first();

        if (!$plan) {
            return response()->json(['message' => 'The selected plan is not available.'], 404);
        }

        $base_amount = round((float) $plan->price_per_month, 2);

        // Validate and apply coupons sequentially
        $coupon_ids      = $request->coupon_ids ?? [];
        $applied_coupons = [];
        $current_amount  = $base_amount;

        foreach ($coupon_ids as $coupon_id) {
            $coupon = Coupon::find($coupon_id);

            if (!$coupon) {
                return response()->json(['message' => 'One or more coupons are no longer valid.'], 422);
            }

            $result = $this->couponService->validateAndCalculate(
                $coupon,
                $current_amount,
                $user->id,
                [],
                []
            );

            if (!$result['valid']) {
                return response()->json(['message' => 'One or more coupons are no longer valid.'], 422);
            }

            $applied_coupons[] = ['coupon' => $coupon, 'discount_amount' => $result['discount_amount']];
            $current_amount    = round($current_amount - $result['discount_amount'], 2);
        }

        $total_coupon_discount = array_sum(array_column($applied_coupons, 'discount_amount'));
        $calculated_total      = round($base_amount - $total_coupon_discount, 2);

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
                'message' => 'Payment failed. Your card was declined.',
            ], 402);
        }

        $order = DB::transaction(function () use ($request, $user, $plan, $calculated_total, $applied_coupons, $payment_intent_id) {
            $order = PremiumMentionsOrder::create([
                'client_id'         => $user->id,
                'plan_id'           => $plan->id,
                'order_notes'       => $request->order_notes,
                'total_amount'      => $calculated_total,
                'status'            => 'pending',
                'payment_intent_id' => $payment_intent_id,
            ]);

            foreach ($applied_coupons as $entry) {
                $order->orderCoupons()->create([
                    'coupon_id'       => $entry['coupon']->id,
                    'discount_amount' => $entry['discount_amount'],
                ]);
            }

            $order->billing()->create([
                'company'     => $request->billing['company'] ?: null,
                'address'     => $request->billing['address'],
                'city'        => $request->billing['city'],
                'state'       => $request->billing['state'],
                'country'     => $request->billing['country'],
                'postal_code' => $request->billing['postal_code'],
            ]);

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
}
