<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    public const TYPES = [
        'purchase',
        'credit_payment',
        'hybrid_payment',
        'failed_purchase',
        'refund',
    ];

    public const STATUSES = ['success', 'failed'];

    public const PAYMENT_METHODS = ['credit_card', 'account_credits', 'hybrid'];

    protected $fillable = [
        'user_id',
        'type',
        'status',
        'amount',
        'payment_method',
        'payment_intent_id',
        'session_id',
        'session_title',
        'order_id',
        'invoice_id',
        'description',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount'   => 'float',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
