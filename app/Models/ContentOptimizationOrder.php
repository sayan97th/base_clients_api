<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use App\Models\Invoice;
use App\Models\LinkBuildingOrderUpdate;
use App\Models\OrderReport;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ContentOptimizationOrder extends Model
{
    use HasUuids;

    public const STATUSES = ['pending', 'processing', 'completed', 'cancelled', 'payment_pending'];

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
    ];

    protected function casts(): array
    {
        return [
            'subtotal_before_discount' => 'float',
            'total_amount'             => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContentOptimizationOrderItem::class, 'order_id');
    }

    public function billing(): HasOne
    {
        return $this->hasOne(ContentOptimizationOrderBilling::class, 'order_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'order_id');
    }

    public function orderCoupons(): HasMany
    {
        return $this->hasMany(ContentOptimizationOrderCoupon::class, 'order_id');
    }

    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(Coupon::class, 'content_optimization_order_coupons', 'order_id', 'coupon_id')
            ->withPivot('discount_amount')
            ->withTimestamps();
    }

    public function intakeRows(): HasManyThrough
    {
        return $this->hasManyThrough(
            ContentOptimizationIntakeRow::class,
            ContentOptimizationOrderItem::class,
            'order_id',
            'item_id',
        );
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
