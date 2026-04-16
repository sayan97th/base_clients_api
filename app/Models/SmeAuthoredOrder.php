<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmeAuthoredOrder extends Model
{
    protected $fillable = [
        'user_id',
        'selected_tiers',
        'billing_address',
        'email',
        'total_amount',
        'status',
        'payment_intent_id',
    ];

    protected $casts = [
        'selected_tiers'  => 'array',
        'billing_address' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
