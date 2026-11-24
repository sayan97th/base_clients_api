<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Discount extends Model
{
    use HasUuids;

    public const APPLIES_TO_ALL = 'all';

    protected $fillable = [
        'name',
        'description',
        'discount_type',
        'discount_rate',
        'min_quantity',
        'applies_to',
        'allow_promo_code_stacking',
        'is_active',
    ];

    protected $casts = [
        'discount_rate'             => 'float',
        'min_quantity'              => 'integer',
        'allow_promo_code_stacking' => 'boolean',
        'is_active'                 => 'boolean',
    ];

    protected $attributes = [
        'allow_promo_code_stacking' => false,
    ];

    public function drTiers(): BelongsToMany
    {
        return $this->belongsToMany(
            DrTier::class,
            'discount_dr_tiers',
            'discount_id',
            'dr_tier_id'
        )->select(['dr_tiers.id', 'dr_tiers.label', 'dr_tiers.price_per_link', 'dr_tiers.is_active']);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Discounts that cover the given product type, including the "all products" ones. */
    public function scopeForProductType(Builder $query, string $product_type): Builder
    {
        return $query->whereIn('applies_to', [$product_type, self::APPLIES_TO_ALL]);
    }

    /**
     * Whether the automatic discount of a product type may be combined with a
     * promo code on the same order (any kind of promo code).
     *
     * Promotions do not stack by default, so this is only true when at least
     * one active discount covers the product type and every one of them has
     * the admin option "allow_promo_code_stacking" enabled.
     */
    public static function allowsPromoCodeStacking(string $product_type): bool
    {
        $stacking_flags = self::query()
            ->active()
            ->forProductType($product_type)
            ->pluck('allow_promo_code_stacking');

        return $stacking_flags->isNotEmpty() && $stacking_flags->every(fn ($flag) => (bool) $flag);
    }

    /**
     * Bulk discount that ends up on an order.
     *
     * Without a promo code the bulk discount always applies. With a promo code
     * (price discount or money back) it is removed, unless the admin allowed
     * combining discounts and promo codes, in which case the client gets both.
     */
    public static function resolveBulkDiscount(
        float $potential_bulk,
        bool $has_promo_code,
        bool $allow_promo_code_stacking
    ): float {
        if (! $has_promo_code || $allow_promo_code_stacking) {
            return $potential_bulk;
        }

        return 0.0;
    }
}
