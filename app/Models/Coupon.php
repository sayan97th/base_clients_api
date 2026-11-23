<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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

    /**
     * Only one promo code may be applied per order (checkout). Promo codes can
     * never be stacked, whatever their type.
     */
    public const MAX_PER_ORDER = 1;

    /** Shown when a client tries to apply more than one promo code to an order. */
    public const COMBINATION_ERROR_MESSAGE = 'This promo cannot be combined with other offers.';

    /** Discount scope values stored in applies_to. */
    public const APPLIES_TO_ALL              = 'all';
    public const APPLIES_TO_DR_TIERS         = 'specific_product';
    public const APPLIES_TO_MINIMUM_PURCHASE = 'minimum_purchase';

    public const APPLIES_TO_OPTIONS = [
        self::APPLIES_TO_ALL,
        self::APPLIES_TO_DR_TIERS,
        self::APPLIES_TO_MINIMUM_PURCHASE,
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
     * Timezone that defines when a coupon's calendar days begin and end.
     */
    public static function promotionTimezone(): string
    {
        return (string) config('app.promotion_timezone', config('app.timezone', 'UTC'));
    }

    /**
     * A calendar date (Y-m-d) is stored as the first second of that day in the
     * promotion timezone, so the coupon is valid for the whole start day.
     * Full date times (Carbon instances or "Y-m-d H:i:s" strings) are kept as is.
     */
    protected function startsAt(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $this->normalizePromotionDate($value, false),
        );
    }

    /**
     * A calendar date (Y-m-d) is stored as the last second of that day in the
     * promotion timezone, so the coupon is valid for the whole expiry day.
     * Full date times (Carbon instances or "Y-m-d H:i:s" strings) are kept as is.
     */
    protected function expiresAt(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $this->normalizePromotionDate($value, true),
        );
    }

    /**
     * First day the coupon can be used (Y-m-d in the promotion timezone).
     */
    public function startDate(): ?string
    {
        return $this->starts_at?->copy()->setTimezone(self::promotionTimezone())->format('Y-m-d');
    }

    /**
     * Last day the coupon can be used (Y-m-d in the promotion timezone).
     */
    public function endDate(): ?string
    {
        return $this->expires_at?->copy()->setTimezone(self::promotionTimezone())->format('Y-m-d');
    }

    /**
     * Today's calendar date in the promotion timezone.
     */
    public static function promotionToday(): string
    {
        return now(self::promotionTimezone())->format('Y-m-d');
    }

    private function normalizePromotionDate(mixed $value, bool $is_end_of_day): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $day    = Carbon::createFromFormat('Y-m-d', $value, self::promotionTimezone());
            $moment = $is_end_of_day ? $day->endOfDay()->startOfSecond() : $day->startOfDay();
        } else {
            $moment = $this->asDateTime($value)->copy();
        }

        return $this->fromDateTime($moment->setTimezone(config('app.timezone', 'UTC')));
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

        return $this->wasRunningAt(now());
    }

    /**
     * Whether the given moment falls inside the promotion dates (starts_at
     * and expires_at). Only the dates are checked, so a request made while
     * the promo was live stays valid even if it is processed later.
     */
    public function wasRunningAt(CarbonInterface $moment): bool
    {
        if ($this->starts_at && $moment->lt($this->starts_at)) {
            return false;
        }

        if ($this->expires_at && $moment->gt($this->expires_at)) {
            return false;
        }

        return true;
    }
}
