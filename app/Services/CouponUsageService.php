<?php

namespace App\Services;

use App\Models\ContentBriefOrder;
use App\Models\ContentOptimizationOrder;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\GiftCardRewardClaim;
use App\Models\LinkBuildingOrder;
use App\Models\NewContentOrder;
use App\Models\PremiumMentionsOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Builds the usage history of a coupon from the coupon_redemptions snapshots,
 * so prices, coupon terms and client details always show what was charged at
 * checkout. Live records are only read for what is meant to be current: the
 * order status, its invoice, the gift card claim status and whether the order
 * still exists.
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

    /** Product type => live order model. */
    private const ORDER_MODELS = [
        'link_building'        => LinkBuildingOrder::class,
        'new_content'          => NewContentOrder::class,
        'content_optimization' => ContentOptimizationOrder::class,
        'content_brief'        => ContentBriefOrder::class,
        'premium_mentions'     => PremiumMentionsOrder::class,
    ];

    /** Product types that can be opened at /admin/orders/{id}. */
    private const LINKABLE_PRODUCT_TYPES = [
        'link_building',
        'new_content',
        'content_optimization',
        'content_brief',
    ];

    /**
     * @return array{summary: array<string, mixed>, data: array<int, array<string, mixed>>}
     */
    public function getUsage(Coupon $coupon): array
    {
        $redemptions = CouponRedemption::query()
            ->where('coupon_id', $coupon->id)
            ->orderByDesc('redeemed_at')
            ->get();

        $live_orders = $this->findLiveOrders($redemptions);
        $claims      = GiftCardRewardClaim::query()
            ->whereIn('id', $redemptions->pluck('gift_card_claim_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $usages = $redemptions
            ->map(fn (CouponRedemption $redemption) => $this->formatUsage(
                $redemption,
                $live_orders->get($redemption->product_type . ':' . $redemption->order_id),
                $redemption->gift_card_claim_id ? $claims->get($redemption->gift_card_claim_id) : null,
            ))
            ->values();

        return [
            'summary' => $this->buildSummary($usages),
            'data'    => $usages->all(),
        ];
    }

    /**
     * @param Collection<int, CouponRedemption> $redemptions
     * @return Collection<string, Model> keyed by "product_type:order_id"
     */
    private function findLiveOrders(Collection $redemptions): Collection
    {
        $found = collect();

        foreach ($redemptions->groupBy('product_type') as $product_type => $group) {
            $model_class = self::ORDER_MODELS[$product_type] ?? null;

            if (! $model_class) {
                continue;
            }

            $query = $model_class::query()->whereIn('id', $group->pluck('order_id')->unique());

            if ($product_type !== 'premium_mentions') {
                $query->with('invoice:id,order_id,invoice_number,status');
            }

            $query->get()->each(fn (Model $order) => $found->put($product_type . ':' . $order->id, $order));
        }

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatUsage(CouponRedemption $redemption, ?Model $live_order, ?GiftCardRewardClaim $claim): array
    {
        $items   = collect($redemption->items ?? []);
        $invoice = $live_order && $live_order->relationLoaded('invoice') ? $live_order->invoice : null;

        return [
            'id'                       => $redemption->id,
            'order_id'                 => $redemption->order_id,
            'order_reference'          => strtoupper(substr($redemption->order_id, 0, 8)),
            'order_title'              => $redemption->order_title,
            'session_id'               => $redemption->session_id,
            'product_type'             => $redemption->product_type,
            'product_label'            => self::PRODUCT_LABELS[$redemption->product_type] ?? ucfirst(str_replace('_', ' ', $redemption->product_type)),
            // Current status when the order still exists, the status at checkout otherwise.
            'status'                   => $live_order?->status ?? $redemption->order_status ?? 'deleted',
            'is_order_deleted'         => $live_order === null,
            'items'                    => $items->map(fn (array $item) => [
                'label'      => $item['label'] ?? 'Item',
                'quantity'   => (int) ($item['quantity'] ?? 0),
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'subtotal'   => (float) ($item['subtotal'] ?? 0),
            ])->values()->all(),
            'items_quantity'           => (int) $items->sum('quantity'),
            'subtotal_before_discount' => $redemption->subtotal_before_discount,
            'discount_amount'          => $redemption->discount_amount,
            'total_amount'             => $redemption->total_amount,
            'reward_amount'            => $redemption->reward_amount,
            'coupon_terms'             => [
                'code'           => $redemption->coupon_code,
                'name'           => $redemption->coupon_name,
                'discount_type'  => $redemption->discount_type,
                'discount_value' => $redemption->discount_value,
            ],
            'gift_card_claim'          => $redemption->gift_card_claim_id ? [
                'id'              => $redemption->gift_card_claim_id,
                'status'          => $claim?->status,
                'reward_amount'   => $claim ? (float) $claim->reward_amount : $redemption->reward_amount,
                'gift_card_brand' => $claim?->gift_card_brand,
            ] : null,
            'invoice'                  => $invoice ? [
                'id'             => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status'         => $invoice->status,
            ] : null,
            'client'                   => $redemption->client_name || $redemption->client_email ? [
                'id'           => $redemption->user_id,
                'name'         => $redemption->client_name ?? $redemption->client_email,
                'email'        => $redemption->client_email,
                'company'      => $redemption->client_company,
                'organization' => $redemption->client_organization,
            ] : null,
            'order_url'                => $live_order && in_array($redemption->product_type, self::LINKABLE_PRODUCT_TYPES, true)
                ? '/admin/orders/' . $redemption->order_id
                : null,
            'ordered_at'               => $redemption->redeemed_at?->toIso8601String(),
            'used_at'                  => $redemption->redeemed_at?->toIso8601String(),
        ];
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
            'client_count'        => $usages->map(fn (array $usage) => $usage['client']['id'] ?? $usage['client']['email'] ?? null)->filter()->unique()->count(),
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
