<?php

namespace App\Http\Controllers\Client\GiftCardReward;

use App\Http\Controllers\Controller;
use App\Http\Requests\GiftCardReward\SubmitGiftCardRewardRequest;
use App\Models\GiftCardRewardClaim;
use App\Models\User;
use App\Services\GiftCardRewardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Client side of money back promotions: lets the client see the gift card
 * they earned on an order and tell us where to send it when they skipped
 * that step at checkout.
 */
class GiftCardRewardController extends Controller
{
    public function __construct(
        protected GiftCardRewardService $giftCardRewardService,
    ) {}

    /**
     * GET /api/gift-card-rewards/lookup?session_id=... or ?order_id=...
     *
     * Returns the reward linked to a checkout or order, or null when that
     * order did not use a gift card reward coupon. Pending claims for a promo
     * that already ended are hidden, the form must not appear anymore.
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'string', 'max:64', 'required_without:order_id'],
            'order_id'   => ['nullable', 'string', 'max:64', 'required_without:session_id'],
        ]);

        /** @var User $user */
        $user  = auth()->user();
        $query = GiftCardRewardClaim::with('coupon')->where('user_id', $user->id);

        if (! empty($data['session_id'])) {
            $query->where('session_id', $data['session_id']);
        } elseif (Str::isUuid($data['order_id'])) {
            $query->whereJsonContains('order_ids', $data['order_id']);
        } else {
            return response()->json(['data' => null]);
        }

        $claim = $query->latest()->first();

        if (! $claim || ($claim->isPendingDetails() && ! $this->giftCardRewardService->isClaimable($claim))) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $this->giftCardRewardService->formatForClient($claim)]);
    }

    /**
     * PUT /api/gift-card-rewards/{id}
     */
    public function submit(SubmitGiftCardRewardRequest $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['message' => 'Gift card reward not found.'], 404);
        }

        /** @var User $user */
        $user  = auth()->user();
        $claim = GiftCardRewardClaim::with('coupon')->find($id);

        if (! $claim || $claim->user_id !== $user->id) {
            return response()->json(['message' => 'Gift card reward not found.'], 404);
        }

        if (! $claim->isPendingDetails()) {
            return response()->json([
                'message' => 'We already have your gift card details for this order.',
            ], 409);
        }

        if (! $this->giftCardRewardService->isClaimable($claim)) {
            return response()->json([
                'message' => 'This promotion has ended, so gift card details can no longer be submitted.',
            ], 422);
        }

        $claim = $this->giftCardRewardService->submitDetails(
            $claim,
            $request->validated('recipient_email'),
            $request->validated('gift_card_brand'),
        );

        return response()->json([
            'message' => 'Thanks! Your gift card details were sent to our team.',
            'data'    => $this->giftCardRewardService->formatForClient($claim),
        ]);
    }
}
