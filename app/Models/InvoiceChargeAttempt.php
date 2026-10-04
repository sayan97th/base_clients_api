<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceChargeAttempt extends Model
{
    use HasUuids;

    public const SOURCE_AUTOPAY = 'autopay';
    public const SOURCE_ADMIN   = 'admin';

    public const STATUS_PROCESSING      = 'processing';
    public const STATUS_SUCCEEDED       = 'succeeded';
    public const STATUS_FAILED          = 'failed';
    public const STATUS_SKIPPED         = 'skipped';
    public const STATUS_REQUIRES_REVIEW = 'requires_review';

    public const SOURCES  = [self::SOURCE_AUTOPAY, self::SOURCE_ADMIN];
    public const STATUSES = [
        self::STATUS_PROCESSING,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
        self::STATUS_REQUIRES_REVIEW,
    ];

    protected $fillable = [
        'invoice_id',
        'user_id',
        'payment_profile_id',
        'source',
        'attempt_number',
        'status',
        'amount',
        'card_brand',
        'card_last_four',
        'stripe_payment_intent_id',
        'idempotency_key',
        'failure_code',
        'failure_message',
        'is_retryable',
        'next_retry_at',
        'initiated_by_id',
        'initiated_by_name',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => 'float',
            'attempt_number' => 'integer',
            'is_retryable'   => 'boolean',
            'next_retry_at'  => 'datetime',
            'completed_at'   => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentProfile(): BelongsTo
    {
        return $this->belongsTo(PaymentProfile::class);
    }
}
