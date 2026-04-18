<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PremiumMentionsOrder extends Model
{
    use HasUuids;

    const STATUSES = ['pending', 'processing', 'completed', 'cancelled'];

    protected $fillable = [
        'client_id',
        'plan_id',
        'order_notes',
        'total_amount',
        'status',
        'payment_intent_id',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'float',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PremiumMentionsPlan::class, 'plan_id');
    }

    public function billing(): HasOne
    {
        return $this->hasOne(PremiumMentionsOrderBilling::class, 'order_id');
    }

    public function orderCoupons(): HasMany
    {
        return $this->hasMany(PremiumMentionsOrderCoupon::class, 'order_id');
    }

    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(Coupon::class, 'premium_mentions_order_coupons', 'order_id', 'coupon_id')
            ->withPivot('discount_amount')
            ->withTimestamps();
    }
}
