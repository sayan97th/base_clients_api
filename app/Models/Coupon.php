<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Coupon extends Model
{
    use HasUuids, SoftDeletes;

    public const DISCOUNT_TYPE_PERCENTAGE       = 'percentage';
    public const DISCOUNT_TYPE_FIXED_AMOUNT     = 'fixed_amount';
    /**
     * Money back promo: the order price is not reduced, instead the client
     * earns a gift card worth discount_value percent of the order total.
     */
    public const DISCOUNT_TYPE_GIFT_CARD_REWARD = 'gift_card_reward';

    public const DISCOUNT_TYPES = [
        self::DISCOUNT_TYPE_PERCENTAGE,
        self::DISCOUNT_TYPE_FIXED_AMOUNT,
        self::DISCOUNT_TYPE_GIFT_CARD_REWARD,
    ];

    protected $fillable = [
        'code',
        'name',
        'description',
        'discount_type',
        'discount_value',
        'applies_to',
        'product_types',
        'dr_tier_id',
        'minimum_purchase_amount',
        'starts_at',
        'expires_at',
        'usage_limit',
        'usage_per_user',
        'times_used',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_value'          => 'float',
            'minimum_purchase_amount' => 'float',
            'usage_limit'             => 'integer',
            'usage_per_user'          => 'integer',
            'times_used'              => 'integer',
            'is_active'               => 'boolean',
            'starts_at'               => 'datetime',
            'expires_at'              => 'datetime',
            'product_types'           => 'array',
        ];
    }

    public function drTier(): BelongsTo
    {
        return $this->belongsTo(DrTier::class, 'dr_tier_id');
    }

    public function drTiers(): BelongsToMany
    {
        return $this->belongsToMany(
            DrTier::class,
            'coupon_dr_tiers',
            'coupon_id',
            'dr_tier_id'
        )->select(['dr_tiers.id', 'dr_tiers.label', 'dr_tiers.price_per_link', 'dr_tiers.is_active']);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(LinkBuildingOrder::class, 'coupon_id');
    }

    public function giftCardRewardClaims(): HasMany
    {
        return $this->hasMany(GiftCardRewardClaim::class, 'coupon_id');
    }

    public function isGiftCardReward(): bool
    {
        return $this->discount_type === self::DISCOUNT_TYPE_GIFT_CARD_REWARD;
    }

    /**
     * Whether the promotion is currently running: active, not deleted,
     * already started and not expired. Uses the same date rules as
     * CouponService::validateAndCalculate so checkout and the post purchase
     * gift card form always agree on whether the promo is still live.
     */
    public function isRunning(): bool
    {
        if (! $this->is_active || $this->trashed()) {
            return false;
        }

        if ($this->starts_at && now()->lt($this->starts_at)) {
            return false;
        }

        if ($this->expires_at && now()->gt($this->expires_at)) {
            return false;
        }

        return true;
    }
}
