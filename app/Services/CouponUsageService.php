<?php

namespace App\Services;

use App\Models\ContentBriefOrder;
use App\Models\ContentBriefOrderCoupon;
use App\Models\ContentOptimizationOrder;
use App\Models\ContentOptimizationOrderCoupon;
use App\Models\Coupon;
use App\Models\GiftCardRewardClaim;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderCoupon;
use App\Models\NewContentOrder;
use App\Models\NewContentOrderCoupon;
use App\Models\PremiumMentionsOrderCoupon;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Builds the usage history of a coupon: every order it was applied to, who
 * placed it and how much it saved. Each product stores the coupon on its own
 * pivot table, and gift card reward coupons are tracked through claims, so
 * this service merges every source into one normalized list.
 */
class CouponUsageService
{
    public const PRODUCT_LABELS = [
        'link_building'        => 'Link Building',
        'new_content'          => 'New Content',
        'content_optimization' => 'Content Optimization',
        'content_brief'        => 'Content Briefs',
        'premium_mentions'     => 'Premium Mentions',
    ];

    /** Product types that can be opened at /admin/orders/{id}. */
    private const LINKABLE_PRODUCT_TYPES = [
        'link_building',
        'new_content',
        'content_optimization',
        'content_brief',
    ];

    /** Order models keyed by product type, used to resolve gift card claim orders. */
    private const ORDER_MODELS = [
        'link_building'        => LinkBuildingOrder::class,
        'new_content'          => NewContentOrder::class,
        'content_optimization' => ContentOptimizationOrder::class,
        'content_brief'        => ContentBriefOrder::class,
    ];

    private const USER_COLUMNS = 'id,first_name,last_name,email,organization_id';

    /**
     * @return array{summary: array<string, mixed>, data: array<int, array<string, mixed>>}
     */
    public function getUsage(Coupon $coupon): array
    {
        $usages = $coupon->isGiftCardReward()
            ? $this->collectGiftCardRewardUsages($coupon)
            : $this->collectOrderCouponUsages($coupon);

        $usages = $usages
            ->sortByDesc(fn (array $usage) => $usage['used_at'] ?? '')
            ->values();

        return [
            'summary' => $this->buildSummary($usages),
            'data'    => $usages->all(),
        ];
    }

    // ── Regular coupons (percentage and fixed amount) ─────────────────────────

    private function collectOrderCouponUsages(Coupon $coupon): Collection
    {
        $order_relations = fn (string $item_relation) => [
            'order.user:' . self::USER_COLUMNS,
            'order.user.organization:id,name',
            'order.billing',
            'order.invoice:id,order_id,invoice_number,status',
            'order.' . $item_relation,
        ];

        $sources = [
            'link_building'        => [LinkBuildingOrderCoupon::class, 'items.drTier', 'drTier'],
            'new_content'          => [NewContentOrderCoupon::class, 'items.tier', 'tier'],
            'content_optimization' => [ContentOptimizationOrderCoupon::class, 'items.tier', 'tier'],
            'content_brief'        => [ContentBriefOrderCoupon::class, 'items.tier', 'tier'],
        ];

        $usages = collect();

        foreach ($sources as $product_type => [$pivot_class, $item_relation, $tier_relation]) {
            $pivot_class::query()
                ->where('coupon_id', $coupon->id)
                ->with($order_relations($item_relation))
                ->get()
                ->each(function (Model $pivot) use ($usages, $product_type, $tier_relation) {
                    if (! $pivot->order) {
                        return;
                    }

                    $usages->push($this->formatUsage(
                        usage_id: (string) $pivot->id,
                        order: $pivot->order,
                        product_type: $product_type,
                        client: $pivot->order->user,
                        items: $this->formatTierItems($pivot->order->items, $tier_relation),
                        discount_amount: (float) $pivot->discount_amount,
                        used_at: $pivot->created_at?->toIso8601String(),
                    ));
                });
        }

        PremiumMentionsOrderCoupon::query()
            ->where('coupon_id', $coupon->id)
            ->with([
                'order.client:' . self::USER_COLUMNS,
                'order.client.organization:id,name',
                'order.billing',
                'order.plan:id,name',
            ])
            ->get()
            ->each(function (PremiumMentionsOrderCoupon $pivot) use ($usages) {
                $order = $pivot->order;

                if (! $order) {
                    return;
                }

                $items = $order->plan
                    ? [[
                        'label'      => $order->plan->name,
                        'quantity'   => 1,
                        'unit_price' => (float) $order->total_amount + (float) $pivot->discount_amount,
                        'subtotal'   => (float) $order->total_amount + (float) $pivot->discount_amount,
                    ]]
                    : [];

                $usages->push($this->formatUsage(
                    usage_id: (string) $pivot->id,
                    order: $order,
                    product_type: 'premium_mentions',
                    client: $order->client,
                    items: $items,
                    discount_amount: (float) $pivot->discount_amount,
                    used_at: $pivot->created_at?->toIso8601String(),
                ));
            });

        return $usages;
    }

    // ── Gift card reward coupons (money back promos) ──────────────────────────

    /**
     * Gift card reward coupons never touch the order coupon tables. Each
     * checkout creates one claim that lists the order ids it covered.
     */
    private function collectGiftCardRewardUsages(Coupon $coupon): Collection
    {
        $claims = GiftCardRewardClaim::query()
            ->where('coupon_id', $coupon->id)
            ->get();

        $order_ids = $claims->pluck('order_ids')->flatten()->filter()->unique()->values();
        $orders    = $this->findOrdersByIds($order_ids->all());
        $usages    = collect();

        foreach ($claims as $claim) {
            $claim_order_ids = collect($claim->order_ids ?? [])->filter()->values();
            $order_count     = max(1, $claim_order_ids->count());

            foreach ($claim_order_ids as $order_id) {
                $resolved = $orders->get($order_id);

                if (! $resolved) {
                    continue;
                }

                [$order, $product_type, $tier_relation] = $resolved;

                $usage = $this->formatUsage(
                    usage_id: $claim->id . ':' . $order_id,
                    order: $order,
                    product_type: $product_type,
                    client: $order->user,
                    items: $this->formatTierItems($order->items, $tier_relation),
                    discount_amount: 0.0,
                    used_at: $claim->created_at?->toIso8601String(),
                );

                // The reward belongs to the whole checkout, split it evenly so
                // per order totals still add up to the claim amount.
                $usage['reward_amount'] = round((float) $claim->reward_amount / $order_count, 2);
                $usage['gift_card_claim'] = [
                    'id'              => $claim->id,
                    'status'          => $claim->status,
                    'reward_amount'   => (float) $claim->reward_amount,
                    'gift_card_brand' => $claim->gift_card_brand,
                ];

                $usages->push($usage);
            }
        }

        return $usages;
    }

    /**
     * @param array<int, string> $order_ids
     * @return Collection<string, array{0: Model, 1: string, 2: string}>
     */
    private function findOrdersByIds(array $order_ids): Collection
    {
        $found = collect();

        if (empty($order_ids)) {
            return $found;
        }

        foreach (self::ORDER_MODELS as $product_type => $model_class) {
            $tier_relation = $product_type === 'link_building' ? 'drTier' : 'tier';

            $model_class::query()
                ->whereIn('id', $order_ids)
                ->with([
                    'user:' . self::USER_COLUMNS,
                    'user.organization:id,name',
                    'billing',
                    'invoice:id,order_id,invoice_number,status',
                    'items.' . $tier_relation,
                ])
                ->get()
                ->each(fn (Model $order) => $found->put($order->id, [$order, $product_type, $tier_relation]));
        }

        return $found;
    }

    // ── Formatting ────────────────────────────────────────────────────────────

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function formatUsage(
        string $usage_id,
        Model $order,
        string $product_type,
        ?User $client,
        array $items,
        float $discount_amount,
        ?string $used_at,
    ): array {
        $total_amount = (float) ($order->total_amount ?? 0);
        $subtotal     = $order->subtotal_before_discount !== null
            ? (float) $order->subtotal_before_discount
            : $total_amount + $discount_amount;

        $invoice = $order->relationLoaded('invoice') ? $order->invoice : null;

        return [
            'id'                       => $usage_id,
            'order_id'                 => (string) $order->id,
            'order_reference'          => strtoupper(substr((string) $order->id, 0, 8)),
            'order_title'              => $order->order_title ?? null,
            'session_id'               => $order->session_id ?? null,
            'product_type'             => $product_type,
            'product_label'            => self::PRODUCT_LABELS[$product_type] ?? ucfirst(str_replace('_', ' ', $product_type)),
            'status'                   => $order->status,
            'items'                    => $items,
            'items_quantity'           => (int) collect($items)->sum('quantity'),
            'subtotal_before_discount' => round($subtotal, 2),
            'discount_amount'          => round($discount_amount, 2),
            'total_amount'             => round($total_amount, 2),
            'reward_amount'            => 0.0,
            'gift_card_claim'          => null,
            'invoice'                  => $invoice ? [
                'id'             => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status'         => $invoice->status,
            ] : null,
            'client'                   => $client ? [
                'id'           => $client->id,
                'name'         => trim($client->first_name . ' ' . $client->last_name) ?: $client->email,
                'email'        => $client->email,
                'company'      => $order->billing?->company ?: null,
                'organization' => $client->organization?->name,
            ] : null,
            'order_url'                => in_array($product_type, self::LINKABLE_PRODUCT_TYPES, true)
                ? '/admin/orders/' . $order->id
                : null,
            'ordered_at'               => $order->created_at?->toIso8601String(),
            'used_at'                  => $used_at ?? $order->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function formatTierItems(iterable $items, string $tier_relation): array
    {
        $formatted = [];

        foreach ($items as $item) {
            $formatted[] = [
                'label'      => $item->{$tier_relation}?->label ?? 'Item',
                'quantity'   => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal'   => (float) $item->subtotal,
            ];
        }

        return $formatted;
    }

    /**
     * @param Collection<int, array<string, mixed>> $usages
     * @return array<string, mixed>
     */
    private function buildSummary(Collection $usages): array
    {
        $order_count = $usages->count();
        $net_revenue = (float) $usages->sum('total_amount');

        // A multi product checkout creates one order per product, the
        // session id groups them back into a single checkout.
        $checkout_count = $usages
            ->map(fn (array $usage) => $usage['session_id'] ?: $usage['order_id'])
            ->unique()
            ->count();

        $by_product_type = $usages
            ->groupBy('product_type')
            ->map(fn (Collection $group, string $product_type) => [
                'product_type'    => $product_type,
                'product_label'   => self::PRODUCT_LABELS[$product_type] ?? $product_type,
                'order_count'     => $group->count(),
                'discount_amount' => round((float) $group->sum('discount_amount'), 2),
                'net_revenue'     => round((float) $group->sum('total_amount'), 2),
            ])
            ->values()
            ->all();

        $used_dates = $usages->pluck('used_at')->filter()->sort()->values();

        return [
            'order_count'         => $order_count,
            'checkout_count'      => $checkout_count,
            'client_count'        => $usages->pluck('client.id')->filter()->unique()->count(),
            'total_discount'      => round((float) $usages->sum('discount_amount'), 2),
            'total_reward'        => round((float) $usages->sum('reward_amount'), 2),
            'gross_revenue'       => round((float) $usages->sum('subtotal_before_discount'), 2),
            'net_revenue'         => round($net_revenue, 2),
            'average_order_value' => $order_count > 0 ? round($net_revenue / $order_count, 2) : 0.0,
            'by_product_type'     => $by_product_type,
            'first_used_at'       => $used_dates->first(),
            'last_used_at'        => $used_dates->last(),
        ];
    }
}
