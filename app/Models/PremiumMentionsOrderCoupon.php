<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PremiumMentionsOrderCoupon extends Model
{
    protected $fillable = [
        'order_id',
        'coupon_id',
        'discount_amount',
    ];

    protected function casts(): array
    {
        return [
            'discount_amount' => 'float',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PremiumMentionsOrder::class, 'order_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }
}
