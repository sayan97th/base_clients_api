<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LinkBuildingOrder extends Model
{
    use HasUuids;

    const STATUSES = ['pending', 'processing', 'completed', 'cancelled'];

    protected $fillable = [
        'user_id',
        'order_title',
        'order_notes',
        'total_amount',
        'status',
        'payment_intent_id',
        'coupon_id',
        'coupon_discount_amount',
        'is_hidden',
    ];

    protected function casts(): array
    {
        return [
            'total_amount'           => 'float',
            'coupon_discount_amount' => 'float',
            'is_hidden'              => 'boolean',
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
}
