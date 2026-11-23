<?php

namespace App\Http\Controllers\Client\Coupon;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coupon\ValidateCouponRequest;
use App\Models\Coupon;
use App\Models\GiftCardRewardClaim;
use App\Services\CouponService;
use Illuminate\Http\JsonResponse;

class CouponValidationController extends Controller
{
    public function __construct(
        protected CouponService $couponService
    ) {}

    /**
     * POST /api/coupons/validate
     *
     * Always returns HTTP 200. Use "valid" field to determine success/failure.
     */
    public function validate(ValidateCouponRequest $request): JsonResponse
    {
        $user                 = auth()->user();
        $code                 = strtoupper(trim($request->code));
        $order_amount         = (float) $request->order_amount;
        $dr_tier_ids          = $request->dr_tier_ids ?? [];
        $dr_tier_amounts      = $request->dr_tier_amounts ?? [];
        $cart_product_types   = $request->cart_product_types ?? [];
        $product_type_amounts = $request->product_type_amounts ?? [];

        // There is no global cart minimum for promo codes. A minimum purchase only
        // applies when an individual coupon is configured with applies_to
        // "minimum_purchase", which CouponService::validateAndCalculate enforces.
        $coupon = Coupon::where('code', $code)->where('is_active', true)->first();

        if (!$coupon) {
            return response()->json($this->buildInvalidResponse($code, 'This promo code is expired or does not exist.'));
        }

        $result = $this->couponService->validateAndCalculate(
            $coupon,
            $order_amount,
            $user->id,
            $dr_tier_ids,
            $dr_tier_amounts,
            $cart_product_types,
            $product_type_amounts
        );

        if (!$result['valid']) {
            return response()->json($this->buildInvalidResponse($code, $result['message']));
        }

        $max_discount_amount = $coupon->maxDiscountAmount();

        return response()->json([
            'valid'                   => true,
            'coupon_id'               => $coupon->id,
            'code'                    => $coupon->code,
            'name'                    => $coupon->name,
            'discount_type'           => $coupon->discount_type,
            'discount_value'          => $coupon->discount_value,
            'applies_to'              => $coupon->applies_to,
            'product_types'           => $coupon->product_types ?? [],
            'dr_tier_id'              => $coupon->dr_tier_id,
            'minimum_purchase_amount' => $coupon->minimum_purchase_amount,
            // Highest discount (or gift card value) one order can get, null when unlimited.
            'max_discount_amount'     => $max_discount_amount,
            'discount_amount'         => $result['discount_amount'],
            'is_gift_card_reward'     => $coupon->isGiftCardReward(),
            'reward_percentage'       => $coupon->isGiftCardReward() ? (float) $coupon->discount_value : null,
            'reward_amount'           => $result['reward_amount'] ?? 0,
            'is_reward_capped'        => $coupon->isGiftCardReward()
                && ($result['reward_amount'] ?? 0) < $this->couponService->calculateUncappedRewardAmount($coupon, $order_amount),
            'gift_card_brands'        => $coupon->isGiftCardReward() ? GiftCardRewardClaim::brandOptions() : [],
            'message'                 => $coupon->isGiftCardReward()
                ? $this->buildGiftCardRewardMessage($coupon, $max_discount_amount)
                : 'Coupon applied successfully.',
        ]);
    }

    /**
     * For example "Promo code applied. You will receive one gift card for this
     * whole order, worth 10% of your order total, up to $1,000."
     */
    private function buildGiftCardRewardMessage(Coupon $coupon, ?float $max_discount_amount): string
    {
        $percentage = rtrim(rtrim(number_format((float) $coupon->discount_value, 2), '0'), '.');
        $message    = "Promo code applied. You will receive one gift card for this whole order, worth {$percentage}% of your order total";

        if ($max_discount_amount !== null) {
            $message .= ', up to $' . $this->formatMoney($max_discount_amount);
        }

        return $message . '.';
    }

    /** 1000 becomes "1,000" and 1000.5 becomes "1,000.50". */
    private function formatMoney(float $amount): string
    {
        return fmod($amount, 1.0) === 0.0
            ? number_format($amount, 0)
            : number_format($amount, 2);
    }

    private function buildInvalidResponse(string $code, string $message): array
    {
        return [
            'valid'                   => false,
            'coupon_id'               => '',
            'code'                    => $code,
            'name'                    => '',
            'discount_type'           => 'percentage',
            'discount_value'          => 0,
            'applies_to'              => 'all',
            'product_types'           => [],
            'dr_tier_id'              => null,
            'minimum_purchase_amount' => null,
            'max_discount_amount'     => null,
            'discount_amount'         => 0,
            'is_gift_card_reward'     => false,
            'reward_percentage'       => null,
            'reward_amount'           => 0,
            'is_reward_capped'        => false,
            'gift_card_brands'        => [],
            'message'                 => $message,
        ];
    }
}
