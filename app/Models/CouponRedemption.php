<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Snapshot of an order bought in a checkout where a coupon was used, taken at
 * checkout. Rows are write once: the prices, coupon terms and client details
 * must keep showing what happened at the moment of the purchase, even when the
 * live records change later.
 *
 * is_coupon_applied is false for the orders of the same checkout the coupon
 * did not discount, so the full checkout can be shown. Those rows never count
 * as a coupon use.
 */
class CouponRedemption extends Model
{
    use HasUuids;

    protected $fillable = [
        'coupon_id',
        'coupon_code',
        'coupon_name',
        'discount_type',
        'discount_value',
        'order_id',
        'product_type',
        'is_coupon_applied',
        'session_id',
        'order_title',
        'order_status',
        'user_id',
        'client_name',
        'client_email',
        'client_company',
        'client_organization',
        'items',
        'subtotal_before_discount',
        'discount_amount',
        'total_amount',
        'reward_amount',
        'gift_card_claim_id',
        'redeemed_at',
    ];

    protected function casts(): array
    {
        return [
            'items'                    => 'array',
            'is_coupon_applied'        => 'boolean',
            'discount_value'           => 'float',
            'subtotal_before_discount' => 'float',
            'discount_amount'          => 'float',
            'total_amount'             => 'float',
            'reward_amount'            => 'float',
            'redeemed_at'              => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Coupon redemptions are an immutable history and cannot be updated.');
        });
    }

    /**
     * Orders the coupon actually discounted (or earned a reward on).
     */
    public function scopeApplied(Builder $query): Builder
    {
        return $query->where('is_coupon_applied', true);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id')->withTrashed();
    }
}
