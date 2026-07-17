<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LinkBuildingOrder extends Model
{
    use HasUuids;

    const STATUSES = ['new_request', 'pending', 'processing', 'completed', 'cancelled', 'payment_pending', 'pending_details'];

    protected $fillable = [
        'user_id',
        'order_title',
        'order_notes',
        'subtotal_before_discount',
        'total_amount',
        'status',
        'payment_intent_id',
        'session_id',
        'session_title',
        'coupon_id',
        'coupon_discount_amount',
        'is_hidden',
        'is_legacy_import',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_before_discount' => 'float',
            'total_amount'             => 'float',
            'coupon_discount_amount'   => 'float',
            'is_hidden'                => 'boolean',
            'is_legacy_import'         => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(LinkBuildingOrderItem::class, 'order_id');
    }

    public function billing(): HasOne
    {
        return $this->hasOne(LinkBuildingOrderBilling::class, 'order_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'order_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function orderCoupons(): HasMany
    {
        return $this->hasMany(LinkBuildingOrderCoupon::class, 'order_id');
    }

    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(Coupon::class, 'link_building_order_coupons', 'order_id', 'coupon_id')
            ->withPivot('discount_amount')
            ->withTimestamps();
    }

    public function updates(): HasMany
    {
        return $this->hasMany(LinkBuildingOrderUpdate::class, 'order_id');
    }

    public function report(): HasOne
    {
        return $this->hasOne(OrderReport::class, 'order_id');
    }
}
