<?php

namespace App\Http\Controllers\Admin\GiftCardReward;

use App\Http\Controllers\Controller;
use App\Models\GiftCardRewardClaim;
use App\Services\GiftCardRewardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Admin side of money back promotions: list the gift cards clients earned
 * and keep track of which ones have already been sent.
 */
class GiftCardRewardClaimController extends Controller
{
    public function __construct(
        protected GiftCardRewardService $giftCardRewardService,
    ) {}

    /**
     * GET /api/admin/gift-card-claims?coupon_id=&session_id=&order_id=&status=
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'coupon_id'  => ['nullable', 'uuid'],
            'session_id' => ['nullable', 'string', 'max:64'],
            'order_id'   => ['nullable', 'uuid'],
            'status'     => ['nullable', Rule::in([
                GiftCardRewardClaim::STATUS_PENDING_DETAILS,
                GiftCardRewardClaim::STATUS_SUBMITTED,
                GiftCardRewardClaim::STATUS_FULFILLED,
            ])],
        ]);

        $claims = GiftCardRewardClaim::with(['user', 'coupon', 'fulfilledBy'])
            ->when($data['coupon_id'] ?? null, fn ($q, $coupon_id) => $q->where('coupon_id', $coupon_id))
            ->when($data['session_id'] ?? null, fn ($q, $session_id) => $q->where('session_id', $session_id))
            ->when($data['order_id'] ?? null, fn ($q, $order_id) => $q->whereJsonContains('order_ids', $order_id))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return response()->json([
            'data' => $claims->map(fn (GiftCardRewardClaim $claim) => $this->giftCardRewardService->formatForAdmin($claim))->values(),
        ]);
    }

    /**
     * PATCH /api/admin/gift-card-claims/{id}/fulfillment
     *
     * Marks a submitted gift card as sent, or reverts it back to submitted.
     */
    public function updateFulfillment(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Gift card request not found.'], 404);
        }

        $data  = $request->validate(['is_fulfilled' => ['required', 'boolean']]);
        $claim = GiftCardRewardClaim::find($id);

        if (! $claim) {
            return response()->json(['message' => 'Gift card request not found.'], 404);
        }

        if ($claim->isPendingDetails()) {
            return response()->json([
                'message' => 'The client has not submitted the gift card details yet.',
            ], 422);
        }

        $claim->update($data['is_fulfilled']
            ? [
                'status'       => GiftCardRewardClaim::STATUS_FULFILLED,
                'fulfilled_at' => now(),
                'fulfilled_by' => auth()->id(),
            ]
            : [
                'status'       => GiftCardRewardClaim::STATUS_SUBMITTED,
                'fulfilled_at' => null,
                'fulfilled_by' => null,
            ]);

        return response()->json([
            'data' => $this->giftCardRewardService->formatForAdmin($claim->fresh(['user', 'coupon', 'fulfilledBy'])),
        ]);
    }
}
