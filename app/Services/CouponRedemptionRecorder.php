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
 * Writes the coupon redemption snapshot of an order: the coupon terms, the
 * client and every item with the price charged at checkout. Snapshots are
 * write once, so recording the same coupon and order twice keeps the first.
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

        return $this->snapshot(
            coupon: $coupon,
            order: $order,
            product_type: $product_type,
            discount_amount: (float) $order_coupon->discount_amount,
            reward_amount: 0.0,
            gift_card_claim_id: null,
            redeemed_at: $order_coupon->created_at,
        );
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
        // limited to some products, only the qualifying orders used it.
        $eligible_types = $coupon->product_types ?? [];

        if (! empty($eligible_types)) {
            $orders = array_values(array_filter(
                $orders,
                fn (array $entry) => in_array($entry[1], $eligible_types, true),
            ));
        }

        if (empty($orders)) {
            return [];
        }

        $reward_shares = $this->splitReward((float) $claim->reward_amount, $orders);
        $redemptions   = [];

        foreach ($orders as $index => [$order, $product_type]) {
            $redemptions[] = $this->snapshot(
                coupon: $coupon,
                order: $order,
                product_type: $product_type,
                discount_amount: 0.0,
                reward_amount: $reward_shares[$index],
                gift_card_claim_id: $claim->id,
                redeemed_at: $claim->created_at,
            );
        }

        return $redemptions;
    }

    /**
     * Splits the checkout reward across its orders in proportion to each
     * order total. The last order takes the rounding remainder so the shares
     * always add up to the exact claim amount.
     *
     * @param array<int, array{0: Model, 1: string}> $orders
     * @return array<int, float>
     */
    private function splitReward(float $reward_amount, array $orders): array
    {
        $order_totals = array_map(fn (array $entry) => max(0.0, (float) $entry[0]->total_amount), $orders);
        $grand_total  = array_sum($order_totals);
        $last_index   = count($orders) - 1;
        $shares       = [];
        $assigned     = 0.0;

        foreach ($order_totals as $index => $order_total) {
            if ($index === $last_index) {
                $shares[$index] = round($reward_amount - $assigned, 2);
                break;
            }

            $share = $grand_total > 0
                ? round($reward_amount * ($order_total / $grand_total), 2)
                : round($reward_amount / count($orders), 2);

            $shares[$index] = $share;
            $assigned      += $share;
        }

        return $shares;
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
        float $discount_amount,
        float $reward_amount,
        ?string $gift_card_claim_id,
        ?Carbon $redeemed_at,
    ): CouponRedemption {
        $client       = $product_type === 'premium_mentions' ? $order->client : $order->user;
        $items        = $this->snapshotItems($order, $product_type, $discount_amount);
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
                'reward_amount'            => round($reward_amount, 2),
                'gift_card_claim_id'       => $gift_card_claim_id,
                'redeemed_at'              => $redeemed_at ?? $order->created_at ?? now(),
            ],
        );
    }

    /**
     * Items are copied with the unit price stored on the order item, which is
     * the price charged at checkout, never the current price of the tier.
     *
     * @return array<int, array<string, mixed>>
     */
    private function snapshotItems(Model $order, string $product_type, float $discount_amount): array
    {
        if ($product_type === 'premium_mentions') {
            $charged_price = round((float) $order->total_amount + $discount_amount, 2);

            return [[
                'tier_id'    => $order->plan_id !== null ? (string) $order->plan_id : null,
                'label'      => $order->plan?->name ?? 'Premium Mentions plan',
                'quantity'   => 1,
                'unit_price' => $charged_price,
                'subtotal'   => $charged_price,
            ]];
        }

        $tier_relation = self::ORDER_MODELS[$product_type][1];
        $tier_key      = $product_type === 'link_building' ? 'dr_tier_id' : 'tier_id';

        return $order->items->map(fn (Model $item) => [
            'tier_id'    => $item->{$tier_key} !== null ? (string) $item->{$tier_key} : null,
            'label'      => $item->{$tier_relation}?->label ?? 'Item',
            'quantity'   => (int) $item->quantity,
            'unit_price' => round((float) $item->unit_price, 2),
            'subtotal'   => round((float) $item->subtotal, 2),
        ])->values()->all();
    }

    private function clientName(?User $client): ?string
    {
        if (! $client) {
            return null;
        }

        return trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')) ?: $client->email;
    }
}
