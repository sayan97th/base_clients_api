<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One billing period of an SEO subscription and the outcome of charging it.
 */
class SeoSubscriptionCharge extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_SUCCEEDED, self::STATUS_FAILED];

    public const TRIGGER_AUTOMATIC = 'automatic';
    public const TRIGGER_MANUAL    = 'manual';
    public const TRIGGER_EXTERNAL  = 'external';

    protected $fillable = [
        'seo_package_subscription_id',
        'user_id',
        'invoice_id',
        'period_start',
        'period_end',
        'amount',
        'status',
        'trigger',
        'attempt_count',
        'last_attempt_at',
        'next_retry_at',
        'payment_intent_id',
        'payment_profile_id',
        'card_label',
        'failure_code',
        'failure_message',
        'charged_by_id',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start'    => 'date',
            'period_end'      => 'date',
            'amount'          => 'float',
            'attempt_count'   => 'integer',
            'last_attempt_at' => 'datetime',
            'next_retry_at'   => 'datetime',
            'paid_at'         => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(SeoPackageSubscription::class, 'seo_package_subscription_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function chargedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'charged_by_id');
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    /**
     * "December 2026", the label used on invoices and emails.
     */
    public function periodLabel(): string
    {
        return $this->period_start->format('F Y');
    }
}
