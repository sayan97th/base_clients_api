<?php

namespace App\Http\Controllers\Client\Coupon;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coupon\ValidateCouponRequest;
use App\Models\Coupon;
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
        $user         = auth()->user();
        $code         = strtoupper(trim($request->code));
        $order_amount = (float) $request->order_amount;
        $dr_tier_ids  = $request->dr_tier_ids ?? [];

        $coupon = Coupon::where('code', $code)->where('is_active', true)->first();

        if (!$coupon) {
            return response()->json($this->buildInvalidResponse($code, 'Coupon not found.'));
        }

        $result = $this->couponService->validateAndCalculate(
            $coupon,
            $order_amount,
            $user->id,
            $dr_tier_ids
        );

        if (!$result['valid']) {
            return response()->json($this->buildInvalidResponse($code, $result['message']));
        }

        return response()->json([
            'valid'                   => true,
            'coupon_id'               => $coupon->id,
            'code'                    => $coupon->code,
            'name'                    => $coupon->name,
            'discount_type'           => $coupon->discount_type,
            'discount_value'          => $coupon->discount_value,
            'applies_to'              => $coupon->applies_to,
            'dr_tier_id'              => $coupon->dr_tier_id,
            'minimum_purchase_amount' => $coupon->minimum_purchase_amount,
            'discount_amount'         => $result['discount_amount'],
            'message'                 => 'Coupon applied successfully.',
        ]);
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
            'dr_tier_id'              => null,
            'minimum_purchase_amount' => null,
            'discount_amount'         => 0,
            'message'                 => $message,
        ];
    }
}
