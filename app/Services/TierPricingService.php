<?php

namespace App\Services;

use App\Models\ContentBriefTier;
use App\Models\ContentOptimizationTier;
use App\Models\DrTier;
use App\Models\NewContentTier;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Resolves the current admin-configured price for a cart tier directly from
 * the database. Cart totals must never be built from a client-supplied
 * unit_price: the frontend cart can hold a price snapshot taken before an
 * admin later changes it, and a client request can submit any value it
 * wants. This service is the single source of truth checkout relies on so
 * the amount charged always matches what the admin currently has configured.
 */
class TierPricingService
{
    private const TIER_MODELS = [
        'link_building'        => [DrTier::class, 'price_per_link'],
        'content_optimization' => [ContentOptimizationTier::class, 'price'],
        'new_content'           => [NewContentTier::class, 'price'],
        'content_brief'         => [ContentBriefTier::class, 'price'],
    ];

    public function resolveUnitPrice(string $product_type, string $tier_id): float
    {
        if (! isset(self::TIER_MODELS[$product_type])) {
            throw new InvalidArgumentException("Unknown product type: {$product_type}");
        }

        [$model_class, $price_column] = self::TIER_MODELS[$product_type];

        /** @var Model|null $tier */
        $tier = $model_class::find($tier_id);

        if (! $tier) {
            throw new DomainException("tier_not_found:{$product_type}:{$tier_id}");
        }

        return (float) $tier->{$price_column};
    }

    /**
     * Overrides each item's unit_price with the current authoritative price
     * for its tier, keyed off dr_tier_id (link building) or tier_id (all
     * other product types). Any unit_price submitted by the client is
     * discarded — every caller downstream then computes subtotals/totals
     * from this corrected array and stays automatically in sync.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public function resolveItemPrices(string $product_type, array $items): array
    {
        $tier_id_key = $product_type === 'link_building' ? 'dr_tier_id' : 'tier_id';

        return array_map(function (array $item) use ($product_type, $tier_id_key) {
            $item['unit_price'] = $this->resolveUnitPrice($product_type, (string) $item[$tier_id_key]);

            return $item;
        }, $items);
    }
}
