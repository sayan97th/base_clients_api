<?php

namespace App\Services;

use App\Jobs\SendAdminGiftCardRewardNotificationJob;
use App\Models\Coupon;
use App\Models\GiftCardRewardClaim;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Money back promotions (for example HAPPYHOLIDAYS): the client pays the
 * full price and earns a gift card worth a percentage of the order total.
 *
 * Reward coupons never reduce the price, so they are kept out of the order
 * coupon tables (which drive discount, bulk discount and invoice logic) and
 * each use is tracked as a GiftCardRewardClaim instead.
 */
class GiftCardRewardService
{
    public function __construct(
        protected CouponService $couponService,
    ) {}

    /**
     * Splits the coupons submitted at checkout into regular discount coupons
     * and the gift card reward coupon (if any).
     *
     * @param  array<int|string, Coupon>  $coupon_models
     * @return array{0: array<int|string, Coupon>, 1: ?Coupon}
     */
    public function splitRewardCoupon(array $coupon_models): array
    {
        $discount_coupons = [];
        $reward_coupon    = null;

        foreach ($coupon_models as $key => $coupon) {
            if ($coupon->isGiftCardReward()) {
                $reward_coupon ??= $coupon;
                continue;
            }

            $discount_coupons[$key] = $coupon;
        }

        return [$discount_coupons, $reward_coupon];
    }

    /**
     * A gift card reward coupon is exclusive: it cannot be combined with any
     * other promo code in the same checkout. Returns the error message to
     * show, or null when the combination is allowed.
     *
     * @param  array<int, string>  $coupon_ids
     */
    public function findCombinationError(array $coupon_ids): ?string
    {
        $coupon_ids = array_values(array_unique(array_filter($coupon_ids)));

        if (count($coupon_ids) < 2) {
            return null;
        }

        $reward_coupon = Coupon::whereIn('id', $coupon_ids)
            ->where('discount_type', Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD)
            ->first();

        return $reward_coupon
            ? "The {$reward_coupon->code} promo code cannot be combined with other promo codes."
            : null;
    }

    /**
     * Turns the optional checkout payload into a details array, or null when
     * the client skipped the gift card form.
     *
     * @return array{recipient_email: ?string, gift_card_brand: ?string}|null
     */
    public function detailsFromCheckout(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        return [
            'recipient_email' => isset($payload['recipient_email']) ? (string) $payload['recipient_email'] : null,
            'gift_card_brand' => isset($payload['gift_card_brand']) ? (string) $payload['gift_card_brand'] : null,
        ];
    }

    /**
     * Same as createClaimForCheckout but never throws: the payment and the
     * orders already succeeded, so a reward problem must not fail the
     * checkout response. Failures are logged for follow up.
     */
    public function safelyCreateClaimForCheckout(
        User $user,
        ?Coupon $coupon,
        string $session_id,
        array $created_orders,
        mixed $details_payload = null,
    ): ?GiftCardRewardClaim {
        if (! $coupon) {
            return null;
        }

        try {
            return $this->createClaimForCheckout(
                $user,
                $coupon,
                $session_id,
                $created_orders,
                $this->detailsFromCheckout($details_payload),
            );
        } catch (Throwable $e) {
            Log::error('Failed to record gift card reward after checkout, manual follow up required.', [
                'user_id'    => $user->id,
                'coupon_id'  => $coupon->id,
                'session_id' => $session_id,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Records the reward earned by a successful checkout. Returns null when
     * the coupon no longer qualifies (expired, usage limit reached, product
     * restrictions, minimum purchase), in which case nothing is created.
     *
     * @param  array<int, array{product_type: string, order_id: string, total_amount: float}>  $created_orders
     * @param  array{recipient_email?: ?string, gift_card_brand?: ?string}|null  $details
     */
    public function createClaimForCheckout(
        User $user,
        Coupon $coupon,
        string $session_id,
        array $created_orders,
        ?array $details = null,
    ): ?GiftCardRewardClaim {
        if (empty($created_orders) || ! $coupon->isGiftCardReward()) {
            return null;
        }

        $order_total          = round(array_sum(array_map(fn ($entry) => (float) $entry['total_amount'], $created_orders)), 2);
        $product_types        = array_values(array_unique(array_column($created_orders, 'product_type')));
        $product_type_amounts = [];

        foreach ($created_orders as $entry) {
            $product_type_amounts[$entry['product_type']] = ($product_type_amounts[$entry['product_type']] ?? 0.0) + (float) $entry['total_amount'];
        }

        $result = $this->couponService->validateAndCalculate(
            $coupon,
            $order_total,
            $user->id,
            [],
            [],
            $product_types,
            $product_type_amounts,
        );

        if (! $result['valid']) {
            Log::info('Gift card reward coupon skipped at checkout because it no longer qualifies.', [
                'user_id'    => $user->id,
                'coupon_id'  => $coupon->id,
                'session_id' => $session_id,
                'reason'     => $result['message'] ?? null,
            ]);

            return null;
        }

        // When the coupon only covers some product types, the reward is based
        // on the qualifying orders only.
        $eligible_total = $order_total;
        $coupon_product_types = $coupon->product_types ?? [];

        if (! empty($coupon_product_types)) {
            $eligible_total = round(array_sum(array_intersect_key($product_type_amounts, array_flip($coupon_product_types))), 2);
        }

        $has_details = $this->hasCompleteDetails($details);

        $claim = GiftCardRewardClaim::create([
            'coupon_id'         => $coupon->id,
            'user_id'           => $user->id,
            'session_id'        => $session_id,
            'order_ids'         => array_values(array_column($created_orders, 'order_id')),
            'product_types'     => $product_types,
            'coupon_code'       => $coupon->code,
            'order_total'       => $eligible_total,
            'reward_percentage' => (float) $coupon->discount_value,
            'reward_amount'     => $this->couponService->calculateRewardAmount($coupon, $eligible_total),
            'recipient_email'   => $has_details ? strtolower(trim($details['recipient_email'])) : null,
            'gift_card_brand'   => $has_details ? $details['gift_card_brand'] : null,
            'submission_source' => $has_details ? GiftCardRewardClaim::SOURCE_CHECKOUT : null,
            'status'            => $has_details ? GiftCardRewardClaim::STATUS_SUBMITTED : GiftCardRewardClaim::STATUS_PENDING_DETAILS,
            'submitted_at'      => $has_details ? now() : null,
        ]);

        $coupon->increment('times_used');

        if ($has_details) {
            $this->notifyAdmins($claim);
        }

        return $claim;
    }

    /**
     * Saves the gift card details the client filled in after checkout (order
     * page or order receipt) and notifies the admin team.
     */
    public function submitDetails(GiftCardRewardClaim $claim, string $recipient_email, string $gift_card_brand): GiftCardRewardClaim
    {
        $claim->update([
            'recipient_email'   => strtolower(trim($recipient_email)),
            'gift_card_brand'   => $gift_card_brand,
            'submission_source' => GiftCardRewardClaim::SOURCE_ORDER_PAGE,
            'status'            => GiftCardRewardClaim::STATUS_SUBMITTED,
            'submitted_at'      => now(),
        ]);

        $this->notifyAdmins($claim);

        return $claim->fresh(['coupon']);
    }

    /**
     * The client can only fill in the form while the claim is waiting for
     * details and the promotion is still running. Once the promo ends the
     * form disappears everywhere.
     */
    public function isClaimable(GiftCardRewardClaim $claim): bool
    {
        $claim->loadMissing('coupon');

        return $claim->isPendingDetails()
            && $claim->coupon !== null
            && $claim->coupon->isGiftCardReward()
            && $claim->coupon->isRunning();
    }

    public function formatForClient(GiftCardRewardClaim $claim): array
    {
        $claim->loadMissing('coupon');

        return [
            'id'                => $claim->id,
            'session_id'        => $claim->session_id,
            'order_ids'         => $claim->order_ids ?? [],
            'coupon_code'       => $claim->coupon_code,
            'promotion_name'    => $claim->coupon?->name ?? $claim->coupon_code,
            'order_total'       => $claim->order_total,
            'reward_percentage' => $claim->reward_percentage,
            'reward_amount'     => $claim->reward_amount,
            'recipient_email'   => $claim->recipient_email,
            'gift_card_brand'   => $claim->gift_card_brand,
            'status'            => $claim->status,
            'is_claimable'      => $this->isClaimable($claim),
            'promotion_ends_at'  => $claim->coupon?->expires_at?->toIso8601String(),
            'promotion_last_day' => $claim->coupon?->endDate(),
            'submitted_at'      => $claim->submitted_at?->toIso8601String(),
            'fulfilled_at'      => $claim->fulfilled_at?->toIso8601String(),
            'brands'            => GiftCardRewardClaim::brandOptions(),
        ];
    }

    public function formatForAdmin(GiftCardRewardClaim $claim): array
    {
        $claim->loadMissing(['user', 'fulfilledBy', 'coupon']);

        return array_merge($this->formatForClient($claim), [
            'coupon_id'             => $claim->coupon_id,
            'gift_card_brand_label' => $claim->brandLabel(),
            'submission_source'     => $claim->submission_source,
            'created_at'            => $claim->created_at?->toIso8601String(),
            'client'                => $claim->user ? [
                'id'      => $claim->user->id,
                'name'    => $claim->user->full_name ?? $claim->user->email,
                'email'   => $claim->user->email,
                'company' => $claim->user->company,
            ] : null,
            'fulfilled_by'          => $claim->fulfilledBy ? [
                'id'   => $claim->fulfilledBy->id,
                'name' => $claim->fulfilledBy->full_name ?? $claim->fulfilledBy->email,
            ] : null,
        ]);
    }

    /**
     * Totals shown on top of the admin gift card requests view: how many
     * requests sit in each status, how much money they represent and which
     * brands clients picked.
     *
     * @param  Collection<int, GiftCardRewardClaim>  $claims
     */
    public function summarizeForAdmin(Collection $claims): array
    {
        $statuses = [
            GiftCardRewardClaim::STATUS_PENDING_DETAILS,
            GiftCardRewardClaim::STATUS_SUBMITTED,
            GiftCardRewardClaim::STATUS_FULFILLED,
        ];

        $by_status = [];
        foreach ($statuses as $status) {
            $status_claims = $claims->where('status', $status);
            $by_status[$status] = [
                'count'        => $status_claims->count(),
                'reward_total' => round((float) $status_claims->sum('reward_amount'), 2),
            ];
        }

        $by_brand = $claims
            ->filter(fn (GiftCardRewardClaim $claim) => $claim->gift_card_brand !== null)
            ->groupBy('gift_card_brand')
            ->map(fn (Collection $brand_claims, string $brand) => [
                'brand'        => $brand,
                'label'        => $brand_claims->first()->brandLabel(),
                'count'        => $brand_claims->count(),
                'reward_total' => round((float) $brand_claims->sum('reward_amount'), 2),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        $created_dates = $claims->pluck('created_at')->filter();

        return [
            'total_count'       => $claims->count(),
            'client_count'      => $claims->pluck('user_id')->filter()->unique()->count(),
            'reward_total'      => round((float) $claims->sum('reward_amount'), 2),
            'order_total'       => round((float) $claims->sum('order_total'), 2),
            'by_status'         => $by_status,
            'by_brand'          => $by_brand,
            'first_created_at'  => $created_dates->min()?->toIso8601String(),
            'last_created_at'   => $created_dates->max()?->toIso8601String(),
        ];
    }

    private function hasCompleteDetails(?array $details): bool
    {
        if (! $details) {
            return false;
        }

        $email = trim((string) ($details['recipient_email'] ?? ''));
        $brand = (string) ($details['gift_card_brand'] ?? '');

        return $email !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && in_array($brand, GiftCardRewardClaim::brandKeys(), true);
    }

    /**
     * Non critical: a failure to queue the email is logged and never breaks
     * the checkout or the client submission.
     */
    private function notifyAdmins(GiftCardRewardClaim $claim): void
    {
        try {
            SendAdminGiftCardRewardNotificationJob::dispatch($claim->id);
        } catch (Throwable $e) {
            Log::warning('Failed to dispatch admin gift card reward notification job.', [
                'claim_id' => $claim->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
