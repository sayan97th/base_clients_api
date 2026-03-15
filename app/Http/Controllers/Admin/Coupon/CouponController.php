<?php

namespace App\Http\Controllers\Admin\Coupon;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Coupon\StoreCouponRequest;
use App\Http\Requests\Admin\Coupon\UpdateCouponRequest;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class CouponController extends Controller
{
    /**
     * GET /api/admin/coupons
     */
    public function index(): JsonResponse
    {
        $coupons = Coupon::with('drTier')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $coupons->map(fn (Coupon $coupon) => $this->formatCoupon($coupon)),
        ]);
    }

    /**
     * GET /api/admin/coupons/{id}
     */
    public function show(string $id): JsonResponse
    {
        $coupon = Coupon::with('drTier')->find($id);

        if (!$coupon) {
            return response()->json(['message' => 'Coupon not found.'], 404);
        }

        return response()->json(['data' => $this->formatCoupon($coupon)]);
    }

    /**
     * POST /api/admin/coupons
     */
    public function store(StoreCouponRequest $request): JsonResponse
    {
        $data = $request->validated();

        $coupon = Coupon::create($data);
        $coupon->load('drTier');

        return response()->json(['data' => $this->formatCoupon($coupon)], 201);
    }

    /**
     * PATCH /api/admin/coupons/{id}
     */
    public function update(UpdateCouponRequest $request, string $id): JsonResponse
    {
        $coupon = Coupon::find($id);

        if (!$coupon) {
            return response()->json(['message' => 'Coupon not found.'], 404);
        }

        $coupon->update($request->validated());
        $coupon->refresh()->load('drTier');

        return response()->json(['data' => $this->formatCoupon($coupon)]);
    }

    /**
     * DELETE /api/admin/coupons/{id}
     */
    public function destroy(string $id): Response|JsonResponse
    {
        $coupon = Coupon::find($id);

        if (!$coupon) {
            return response()->json(['message' => 'Coupon not found.'], 404);
        }

        $coupon->delete();

        return response()->noContent();
    }

    private function formatCoupon(Coupon $coupon): array
    {
        return [
            'id'                      => $coupon->id,
            'code'                    => $coupon->code,
            'name'                    => $coupon->name,
            'description'             => $coupon->description,
            'discount_type'           => $coupon->discount_type,
            'discount_value'          => $coupon->discount_value,
            'applies_to'              => $coupon->applies_to,
            'dr_tier_id'              => $coupon->dr_tier_id,
            'dr_tier_label'           => $coupon->drTier?->dr_label,
            'minimum_purchase_amount' => $coupon->minimum_purchase_amount,
            'starts_at'               => $coupon->starts_at?->format('Y-m-d'),
            'expires_at'              => $coupon->expires_at->format('Y-m-d'),
            'usage_limit'             => $coupon->usage_limit,
            'usage_per_user'          => $coupon->usage_per_user,
            'times_used'              => $coupon->times_used,
            'is_active'               => $coupon->is_active,
            'created_at'              => $coupon->created_at?->toIso8601String(),
            'updated_at'              => $coupon->updated_at?->toIso8601String(),
        ];
    }
}
