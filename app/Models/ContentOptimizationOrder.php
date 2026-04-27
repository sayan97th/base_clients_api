<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ContentOptimizationOrder extends Model
{
    use HasUuids;

    const STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

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
}
