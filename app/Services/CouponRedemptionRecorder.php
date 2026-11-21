<?php

namespace App\Services;

use App\Models\ContentBriefOrder;
use App\Models\ContentBriefOrderCoupon;
use App\Models\ContentOptimizationOrder;
use App\Models\ContentOptimizationOrderCoupon;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\GiftCardRewardClaim;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderCoupon;
use App\Models\NewContentOrder;
use App\Models\NewContentOrderCoupon;
use App\Models\PremiumMentionsOrder;
use App\Models\PremiumMentionsOrderCoupon;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the coupon redemption snapshot of a checkout: the coupon terms, the
 * client and every item with the price charged at checkout. A coupon is used
 * once per checkout, and a checkout creates one order per product, so every
 * order of that checkout is stored. Orders the coupon did not discount are
 * stored with is_coupon_applied false, and each item says whether the coupon
 * applied to it, so the admin can see the whole purchase and which products
 * got the promo. Snapshots are write once, so recording the same coupon and
 * order twice keeps the first.
 *
 * Gift card reward coupons earn ONE gift card per checkout (the whole order),
 * never one per product or per item. The amount lives on the
 * GiftCardRewardClaim, so the product order snapshots only record that the
 * promo applied and link to the claim. They never carry a share of it.
 */
class CouponRedemptionRecorder
{
    /** Order coupon pivot class => product type. */
    public const ORDER_COUPON_SOURCES = [
        LinkBuildingOrderCoupon::class        => 'link_building',
        NewContentOrderCoupon::class          => 'new_content',
        ContentOptimizationOrderCoupon::class => 'content_optimization',
        ContentBriefOrderCoupon::class        => 'content_brief',
        PremiumMentionsOrderCoupon::class     => 'premium_mentions',
    ];

    /** Product type => [order model, item tier relation]. */
    private const ORDER_MODELS = [
        'link_building'        => [LinkBuildingOrder::class, 'drTier'],
        'new_content'          => [NewContentOrder::class, 'tier'],
        'content_optimization' => [ContentOptimizationOrder::class, 'tier'],
        'content_brief'        => [ContentBriefOrder::class, 'tier'],
    ];

    /**
     * Records a coupon applied to an order. Never throws, a failure is logged
     * so it can never break a checkout.
     */
    public function safelyRecordOrderCoupon(Model $order_coupon): void
    {
        try {
            $this->recordOrderCoupon($order_coupon);
        } catch (Throwable $exception) {
            Log::error('Coupon redemption snapshot could not be recorded.', [
                'order_coupon' => $order_coupon::class,
                'order_id'     => $order_coupon->order_id ?? null,
                'coupon_id'    => $order_coupon->coupon_id ?? null,
                'error'        => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Records every order covered by a gift card reward claim. Never throws.
     */
    public function safelyRecordGiftCardClaim(GiftCardRewardClaim $claim): void
    {
        try {
            $this->recordGiftCardClaim($claim);
        } catch (Throwable $exception) {
            Log::error('Gift card reward redemption snapshot could not be recorded.', [
                'claim_id'  => $claim->id,
                'coupon_id' => $claim->coupon_id,
                'error'     => $exception->getMessage(),
            ]);
        }
    }

    public function recordOrderCoupon(Model $order_coupon): ?CouponRedemption
    {
        $product_type = self::ORDER_COUPON_SOURCES[$order_coupon::class] ?? null;
        $coupon       = Coupon::withTrashed()->find($order_coupon->coupon_id);

        if (! $product_type || ! $coupon) {
            return null;
        }

        $order = $this->findOrder($product_type, (string) $order_coupon->order_id);

        if (! $order) {
            return null;
        }

        $redemption = $this->snapshot(
            coupon: $coupon,
            order: $order,
            product_type: $product_type,
            is_coupon_applied: true,
            discount_amount: (float) $order_coupon->discount_amount,
            gift_card_claim_id: null,
            redeemed_at: $order_coupon->created_at,
        );

        $this->recordCheckoutCompanions(
            coupon: $coupon,
            session_id: $order->session_id ?? null,
            user_id: $this->clientOf($order, $product_type)?->id,
            recorded_keys: [$this->orderKey($product_type, (string) $order->id)],
            gift_card_claim_id: null,
            redeemed_at: $redemption->redeemed_at,
        );

        return $redemption;
    }

    /**
     * @return array<int, CouponRedemption>
     */
    public function recordGiftCardClaim(GiftCardRewardClaim $claim): array
    {
        $coupon    = Coupon::withTrashed()->find($claim->coupon_id);
        $order_ids = collect($claim->order_ids ?? [])->filter()->values();

        if (! $coupon || $order_ids->isEmpty()) {
            return [];
        }

        $orders = [];

        foreach ($order_ids as $order_id) {
            foreach (array_keys(self::ORDER_MODELS) as $product_type) {
                $order = $this->findOrder($product_type, (string) $order_id);

                if ($order) {
                    $orders[] = [$order, $product_type];
                    break;
                }
            }
        }

        // The claim lists every order of the checkout. When the promo is
        // limited to some products, only the qualifying orders count toward
        // the gift card, the others are kept as orders of the checkout without
        // the coupon. Either way the checkout earns a single gift card.
        $eligible_types = $coupon->product_types ?? [];
        $is_eligible    = fn (array $entry) => empty($eligible_types) || in_array($entry[1], $eligible_types, true);
        $eligible       = array_values(array_filter($orders, $is_eligible));
        $not_eligible   = array_values(array_filter($orders, fn (array $entry) => ! $is_eligible($entry)));

        if (empty($eligible)) {
            return [];
        }

        $redemptions   = [];
        $recorded_keys = [];

        foreach ($eligible as [$order, $product_type]) {
            $redemptions[] = $this->snapshot(
                coupon: $coupon,
                order: $order,
                product_type: $product_type,
                is_coupon_applied: true,
                discount_amount: 0.0,
                gift_card_claim_id: $claim->id,
                redeemed_at: $claim->created_at,
            );
            $recorded_keys[] = $this->orderKey($product_type, (string) $order->id);
        }

        foreach ($not_eligible as [$order, $product_type]) {
            $this->snapshot(
                coupon: $coupon,
                order: $order,
                product_type: $product_type,
                is_coupon_applied: false,
                discount_amount: 0.0,
                gift_card_claim_id: $claim->id,
                redeemed_at: $claim->created_at,
            );
            $recorded_keys[] = $this->orderKey($product_type, (string) $order->id);
        }

        $this->recordCheckoutCompanions(
            coupon: $coupon,
            session_id: $claim->session_id,
            user_id: $claim->user_id,
            recorded_keys: $recorded_keys,
            gift_card_claim_id: $claim->id,
            redeemed_at: $claim->created_at,
        );

        return $redemptions;
    }

    /**
     * Stores the other orders of the checkout (same session) that the coupon
     * did not discount, so the usage history can show the complete purchase.
     * An order that has its own coupon pivot is skipped, it is recorded as a
     * discounted order by its own pivot.
     *
     * @param array<int, string> $recorded_keys "product_type:order_id" of the orders already stored
     */
    private function recordCheckoutCompanions(
        Coupon $coupon,
        ?string $session_id,
        int|string|null $user_id,
        array $recorded_keys,
        ?string $gift_card_claim_id,
        ?Carbon $redeemed_at,
    ): void {
        if (! $session_id) {
            return;
        }

        foreach (self::ORDER_MODELS as $product_type => [$model_class, $tier_relation]) {
            $companions = $model_class::with(['user.organization', 'billing', 'items.' . $tier_relation])
                ->where('session_id', $session_id)
                ->when($user_id !== null, fn ($query) => $query->where('user_id', $user_id))
                ->get();

            foreach ($companions as $order) {
                if (in_array($this->orderKey($product_type, (string) $order->id), $recorded_keys, true)) {
                    continue;
                }

                if (! $coupon->isGiftCardReward() && $order->orderCoupons()->where('coupon_id', $coupon->id)->exists()) {
                    continue;
                }

                $this->snapshot(
                    coupon: $coupon,
                    order: $order,
                    product_type: $product_type,
                    is_coupon_applied: false,
                    discount_amount: 0.0,
                    gift_card_claim_id: $gift_card_claim_id,
                    redeemed_at: $redeemed_at,
                );
            }
        }
    }

    private function orderKey(string $product_type, string $order_id): string
    {
        return $product_type . ':' . $order_id;
    }

    private function clientOf(Model $order, string $product_type): ?User
    {
        return $product_type === 'premium_mentions' ? $order->client : $order->user;
    }

    private function findOrder(string $product_type, string $order_id): ?Model
    {
        if ($product_type === 'premium_mentions') {
            return PremiumMentionsOrder::with(['client.organization', 'billing', 'plan'])->find($order_id);
        }

        [$model_class, $tier_relation] = self::ORDER_MODELS[$product_type];

        return $model_class::with(['user.organization', 'billing', 'items.' . $tier_relation])->find($order_id);
    }

    private function snapshot(
        Coupon $coupon,
        Model $order,
        string $product_type,
        bool $is_coupon_applied,
        float $discount_amount,
        ?string $gift_card_claim_id,
        ?Carbon $redeemed_at,
    ): CouponRedemption {
        $client       = $this->clientOf($order, $product_type);
        $items        = $this->snapshotItems($order, $product_type, $coupon, $is_coupon_applied, $discount_amount);
        $total_amount = (float) $order->total_amount;
        $subtotal     = $order->subtotal_before_discount !== null
            ? (float) $order->subtotal_before_discount
            : $total_amount + $discount_amount;

        return CouponRedemption::firstOrCreate(
            [
                'coupon_id'    => $coupon->id,
                'product_type' => $product_type,
                'order_id'     => (string) $order->id,
            ],
            [
                'coupon_code'              => $coupon->code,
                'coupon_name'              => $coupon->name,
                'discount_type'            => $coupon->discount_type,
                'discount_value'           => (float) $coupon->discount_value,
                'is_coupon_applied'        => $is_coupon_applied,
                'session_id'               => $order->session_id ?? null,
                'order_title'              => $order->order_title ?? null,
                'order_status'             => $order->status,
                'user_id'                  => $client?->id,
                'client_name'              => $this->clientName($client),
                'client_email'             => $client?->email,
                'client_company'           => $order->billing?->company ?: null,
                'client_organization'      => $client?->organization?->name,
                'items'                    => $items,
                'subtotal_before_discount' => round($subtotal, 2),
                'discount_amount'          => round($discount_amount, 2),
                'total_amount'             => round($total_amount, 2),
                // The gift card is earned once per checkout and stored on the
                // claim, a product order never holds a share of it.
                'reward_amount'            => 0.0,
                'gift_card_claim_id'       => $gift_card_claim_id,
                'redeemed_at'              => $redeemed_at ?? $order->created_at ?? now(),
            ],
        );
    }

    /**
     * Items are copied with the unit price stored on the order item, which is
     * the price charged at checkout, never the current price of the tier.
     * Each item records whether the coupon applied to it and its share of the
     * order discount, split by item subtotal. Gift card rewards are never split
     * across items, the checkout earns one gift card for the whole order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function snapshotItems(
        Model $order,
        string $product_type,
        Coupon $coupon,
        bool $is_coupon_applied,
        float $discount_amount,
    ): array {
        if ($product_type === 'premium_mentions') {
            $charged_price = round((float) $order->total_amount + $discount_amount, 2);

            return [[
                'tier_id'           => $order->plan_id !== null ? (string) $order->plan_id : null,
                'label'             => $order->plan?->name ?? 'Premium Mentions plan',
                'quantity'          => 1,
                'unit_price'        => $charged_price,
                'subtotal'          => $charged_price,
                'is_coupon_applied' => $is_coupon_applied,
                'discount_amount'   => $is_coupon_applied ? round($discount_amount, 2) : 0.0,
            ]];
        }

        $tier_relation = self::ORDER_MODELS[$product_type][1];
        $tier_key      = $product_type === 'link_building' ? 'dr_tier_id' : 'tier_id';

        $items = $order->items->map(fn (Model $item) => [
            'tier_id'    => $item->{$tier_key} !== null ? (string) $item->{$tier_key} : null,
            'label'      => $item->{$tier_relation}?->label ?? 'Item',
            'quantity'   => (int) $item->quantity,
            'unit_price' => round((float) $item->unit_price, 2),
            'subtotal'   => round((float) $item->subtotal, 2),
        ])->values()->all();

        $eligible_indexes = $is_coupon_applied
            ? $this->eligibleItemIndexes($items, $product_type, $coupon)
            : [];

        $discount_shares = $this->splitAcrossItems($discount_amount, $items, $eligible_indexes);

        foreach ($items as $index => $item) {
            $items[$index]['is_coupon_applied'] = in_array($index, $eligible_indexes, true);
            $items[$index]['discount_amount']   = $discount_shares[$index] ?? 0.0;
        }

        return $items;
    }

    /**
     * Indexes of the items the coupon applied to. A coupon limited to some
     * DR tiers only discounts those link building items, otherwise every
     * item of a discounted order counts.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, int>
     */
    private function eligibleItemIndexes(array $items, string $product_type, Coupon $coupon): array
    {
        $all_indexes = array_keys($items);

        if ($product_type !== 'link_building' || $coupon->applies_to !== Coupon::APPLIES_TO_DR_TIERS) {
            return $all_indexes;
        }

        $coupon_tier_ids  = $coupon->drTiers()->pluck('dr_tiers.id')->map(fn ($id) => (string) $id)->all();
        $eligible_indexes = array_keys(array_filter(
            $items,
            fn (array $item) => in_array($item['tier_id'], $coupon_tier_ids, true),
        ));

        // The order was discounted, so when the tier list changed since the
        // checkout the whole order is the best record of what got the promo.
        return empty($eligible_indexes) ? $all_indexes : array_values($eligible_indexes);
    }

    /**
     * Splits an order amount across the eligible items in proportion to their
     * subtotal. The last item takes the rounding remainder so the shares add
     * up to the exact order amount.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<int, int>                  $eligible_indexes
     * @return array<int, float> keyed by item index
     */
    private function splitAcrossItems(float $amount, array $items, array $eligible_indexes): array
    {
        if ($amount <= 0 || empty($eligible_indexes)) {
            return [];
        }

        $eligible_total = array_sum(array_map(fn (int $index) => max(0.0, (float) $items[$index]['subtotal']), $eligible_indexes));
        $last_index     = end($eligible_indexes);
        $shares         = [];
        $assigned       = 0.0;

        foreach ($eligible_indexes as $index) {
            if ($index === $last_index) {
                $shares[$index] = round($amount - $assigned, 2);
                break;
            }

            $share = $eligible_total > 0
                ? round($amount * (max(0.0, (float) $items[$index]['subtotal']) / $eligible_total), 2)
                : round($amount / count($eligible_indexes), 2);

            $shares[$index] = $share;
            $assigned      += $share;
        }

        return $shares;
    }

    private function clientName(?User $client): ?string
    {
        if (! $client) {
            return null;
        }

        return trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')) ?: $client->email;
    }
}
