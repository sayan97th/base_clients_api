<?php

namespace App\Http\Controllers\Admin\Coupon;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Coupon\StoreCouponRequest;
use App\Http\Requests\Admin\Coupon\UpdateCouponRequest;
use App\Http\Requests\Admin\Coupon\UpdateCouponStatusRequest;
use App\Models\Coupon;
use App\Models\GiftCardRewardClaim;
use App\Services\CouponUsageService;
use App\Services\GiftCardRewardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CouponController extends Controller
{
    /**
     * GET /api/admin/coupons
     */
    public function index(): JsonResponse
    {
        $coupons = Coupon::with('drTiers')
            ->withCount([
                'giftCardRewardClaims as gift_card_claims_count',
                'giftCardRewardClaims as gift_card_claims_awaiting_count' => fn ($q) => $q->where('status', 'submitted'),
            ])
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
        $coupon = Coupon::with('drTiers')
            ->withCount([
                'giftCardRewardClaims as gift_card_claims_count',
                'giftCardRewardClaims as gift_card_claims_awaiting_count' => fn ($q) => $q->where('status', 'submitted'),
            ])
            ->find($id);

        if (!$coupon) {
            return response()->json(['message' => 'Coupon not found.'], 404);
        }

        return response()->json(['data' => $this->formatCoupon($coupon)]);
    }

    /**
     * GET /api/admin/coupons/{id}/usages
     *
     * Every order the coupon was applied to, with the client who placed it,
     * the purchased items and the discount it produced. Soft deleted coupons
     * are included so their history stays available.
     */
    public function usages(string $id, CouponUsageService $coupon_usage_service): JsonResponse
    {
        $coupon = Coupon::withTrashed()
            ->with('drTiers')
            ->withCount([
                'giftCardRewardClaims as gift_card_claims_count',
                'giftCardRewardClaims as gift_card_claims_awaiting_count' => fn ($q) => $q->where('status', 'submitted'),
            ])
            ->find($id);

        if (!$coupon) {
            return response()->json(['message' => 'Coupon not found.'], 404);
        }

        $usage = $coupon_usage_service->getUsage($coupon);

        return response()->json([
            'coupon'    => $this->formatCoupon($coupon),
            'summary'   => $usage['summary'],
            'data'      => $usage['data'],
            'checkouts' => $usage['checkouts'],
        ]);
    }

    /**
     * GET /api/admin/coupons/{id}/gift-card-claims
     *
     * Every gift card request of one money back coupon, with the totals the
     * dedicated admin view and its export need. Unlike the generic list of
     * gift card requests this endpoint is not capped, so an export always
     * contains every row.
     */
    public function giftCardClaims(string $id, GiftCardRewardService $gift_card_reward_service): JsonResponse
    {
        $coupon = Coupon::withTrashed()
            ->with('drTiers')
            ->withCount([
                'giftCardRewardClaims as gift_card_claims_count',
                'giftCardRewardClaims as gift_card_claims_awaiting_count' => fn ($q) => $q->where('status', GiftCardRewardClaim::STATUS_SUBMITTED),
            ])
            ->find($id);

        if (!$coupon) {
            return response()->json(['message' => 'Coupon not found.'], 404);
        }

        if (!$coupon->isGiftCardReward()) {
            return response()->json(['message' => 'This coupon does not reward gift cards.'], 422);
        }

        $claims = GiftCardRewardClaim::with(['user', 'coupon', 'fulfilledBy'])
            ->where('coupon_id', $coupon->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'coupon'  => $this->formatCoupon($coupon),
            'summary' => $gift_card_reward_service->summarizeForAdmin($claims),
            'data'    => $claims->map(fn (GiftCardRewardClaim $claim) => $gift_card_reward_service->formatForAdmin($claim))->values(),
        ]);
    }

    /**
     * POST /api/admin/coupons
     */
    public function store(StoreCouponRequest $request): JsonResponse
    {
        $validated   = $this->normalizeScopeFields($request->validated(), $request->validated('applies_to'));
        $validated   = $this->normalizeMaxDiscount($validated, $request->validated('discount_type'));
        $dr_tier_ids = $validated['dr_tier_ids'] ?? [];
        unset($validated['dr_tier_ids']);

        $validated['dr_tier_id'] = null;

        $coupon = DB::transaction(function () use ($validated, $dr_tier_ids) {
            $coupon = Coupon::create($validated);

            if (!empty($dr_tier_ids)) {
                $coupon->drTiers()->sync($dr_tier_ids);
            }

            return $coupon;
        });

        $coupon->load('drTiers');

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

        $applies_to  = $request->effectiveAppliesTo();
        $validated   = $this->normalizeScopeFields($request->validated(), $applies_to);
        $validated   = $this->normalizeMaxDiscount($validated, $request->effectiveDiscountType());
        $dr_tier_ids = $validated['dr_tier_ids'] ?? null;
        unset($validated['dr_tier_ids']);

        DB::transaction(function () use ($coupon, $validated, $dr_tier_ids) {
            $coupon->update($validated);

            if ($dr_tier_ids !== null) {
                $coupon->drTiers()->sync($dr_tier_ids);
            }
        });

        return response()->json(['data' => $this->formatCoupon($this->loadCouponDetails($coupon->refresh()))]);
    }

    /**
     * PATCH /api/admin/coupons/{id}/status
     *
     * Quick enable or disable. It skips the full edit validation so a coupon
     * can always be switched off right away, for example during an incident.
     */
    public function updateStatus(UpdateCouponStatusRequest $request, string $id): JsonResponse
    {
        $coupon = Coupon::find($id);

        if (!$coupon) {
            return response()->json(['message' => 'Coupon not found.'], 404);
        }

        $coupon->update(['is_active' => $request->boolean('is_active')]);

        return response()->json(['data' => $this->formatCoupon($this->loadCouponDetails($coupon))]);
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

    /**
     * Keeps the scope fields consistent with the selected scope: DR tiers are
     * only stored for the DR Tiers scope and the minimum amount only for the
     * Minimum Purchase scope. Stale values from a previous scope are cleared.
     */
    private function normalizeScopeFields(array $validated, ?string $applies_to): array
    {
        if ($applies_to !== Coupon::APPLIES_TO_DR_TIERS) {
            $validated['dr_tier_ids'] = [];
        }

        if ($applies_to !== Coupon::APPLIES_TO_MINIMUM_PURCHASE) {
            $validated['minimum_purchase_amount'] = null;
        }

        return $validated;
    }

    /**
     * Fixed amount coupons already define the exact discount, so a ceiling
     * never applies to them and any stale value is cleared.
     */
    private function normalizeMaxDiscount(array $validated, ?string $discount_type): array
    {
        if (! Coupon::supportsMaxDiscount($discount_type)) {
            $validated['max_discount_amount'] = null;
        }

        return $validated;
    }

    private function loadCouponDetails(Coupon $coupon): Coupon
    {
        return $coupon->load('drTiers')->loadCount([
            'giftCardRewardClaims as gift_card_claims_count',
            'giftCardRewardClaims as gift_card_claims_awaiting_count' => fn ($q) => $q->where('status', 'submitted'),
        ]);
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
            'dr_tier_ids'             => $coupon->drTiers->pluck('id')->values()->toArray(),
            'dr_tiers'                => $coupon->drTiers->values()->toArray(),
            'minimum_purchase_amount' => $coupon->minimum_purchase_amount,
            // Highest discount (or gift card value) one order can get, null when unlimited.
            'max_discount_amount'     => $coupon->max_discount_amount,
            // Calendar days in the promotion timezone, both days included.
            'starts_at'               => $coupon->startDate(),
            'expires_at'              => $coupon->endDate(),
            // Exact moments the coupon starts and stops working, for status badges.
            'active_from'             => $coupon->starts_at?->toIso8601String(),
            'active_until'            => $coupon->expires_at?->toIso8601String(),
            'usage_limit'             => $coupon->usage_limit,
            'usage_per_user'          => $coupon->usage_per_user,
            'times_used'              => $coupon->times_used,
            'is_active'               => $coupon->is_active,
            'is_gift_card_reward'     => $coupon->isGiftCardReward(),
            'gift_card_claims_count'  => (int) ($coupon->gift_card_claims_count ?? 0),
            'gift_card_claims_awaiting_count' => (int) ($coupon->gift_card_claims_awaiting_count ?? 0),
            'created_at'              => $coupon->created_at?->toIso8601String(),
            'updated_at'              => $coupon->updated_at?->toIso8601String(),
        ];
    }
}
