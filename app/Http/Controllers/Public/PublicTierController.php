<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\ContentBriefTier;
use App\Models\ContentOptimizationTier;
use App\Models\DrTier;
use App\Models\NewContentTier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

/**
 * Unauthenticated tier catalog for external sites (e.g. the marketing site's
 * cart) to display current pricing. Never a checkout trust boundary: the
 * cart/checkout endpoints always re-resolve unit_price server-side via
 * TierPricingService, so a stale or spoofed price read here can never
 * affect what a customer is actually charged.
 */
class PublicTierController extends Controller
{
    private const TIER_MODELS = [
        'link_building'        => [DrTier::class, 'price_per_link'],
        'content_optimization' => [ContentOptimizationTier::class, 'price'],
        'new_content'          => [NewContentTier::class, 'price'],
        'content_brief'        => [ContentBriefTier::class, 'price'],
    ];

    public function index(string $product_type): JsonResponse
    {
        if (! isset(self::TIER_MODELS[$product_type])) {
            return response()->json(['message' => 'Unknown product type.'], 404);
        }

        [$model_class, $price_column] = self::TIER_MODELS[$product_type];

        /** @var Model $model_class */
        $tiers = $model_class::where('is_active', true)
            ->where('is_hidden', false)
            ->orderBy($price_column)
            ->get()
            ->map(fn ($tier) => [
                'id'              => $tier->id,
                'label'           => $tier->label,
                'price'           => (float) $tier->{$price_column},
                'is_most_popular' => (bool) $tier->is_most_popular,
                'max_quantity'    => $tier->max_quantity,
            ]);

        return response()->json(['data' => $tiers]);
    }
}
