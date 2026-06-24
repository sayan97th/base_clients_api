<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditPurchase extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'package_id',
        'package_name',
        'credits_amount',
        'amount_paid',
        'payment_intent_id',
        'status',
    ];

    protected $casts = [
        'credits_amount' => 'integer',
        'amount_paid'    => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
