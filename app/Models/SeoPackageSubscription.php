<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class SeoPackageSubscription extends Model
{
    const STATUSES = ['active', 'cancelled', 'expired'];

    public const PRICING_PACKAGE = 'package';
    public const PRICING_CUSTOM  = 'custom';
    public const PRICING_TYPES   = [self::PRICING_PACKAGE, self::PRICING_CUSTOM];

    public const PAYMENT_STATUS_CURRENT  = 'current';
    public const PAYMENT_STATUS_PAST_DUE = 'past_due';

    public const DEFAULT_INVOICE_ITEM_LABEL = 'SEO Services';

    /**
     * Renewal reminder bounds. The admin can pick any number of days, capped
     * at the shortest month so a reminder never goes out before the previous
     * charge of a monthly subscription.
     */
    public const DEFAULT_REMINDER_DAYS = 3;
    public const MIN_REMINDER_DAYS     = 1;
    public const MAX_REMINDER_DAYS     = 28;

    protected $fillable = [
        'user_id',
        'seo_package_id',
        'plan_name',
        'pricing_type',
        'monthly_price',
        'invoice_item_label',
        'invoice_item_description',
        'auto_renew',
        'payment_profile_id',
        'billing_anchor_day',
        'next_billing_date',
        'reminder_days_before',
        'last_reminder_sent_for',
        'payment_status',
        'auto_renew_disabled_at',
        'status',
        'starts_at',
        'ends_at',
        'cancelled_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at'              => 'date',
            'ends_at'                => 'date',
            'cancelled_at'           => 'datetime',
            'monthly_price'          => 'float',
            'auto_renew'             => 'boolean',
            'billing_anchor_day'     => 'integer',
            'next_billing_date'      => 'date',
            'reminder_days_before'   => 'integer',
            'last_reminder_sent_for' => 'date',
            'auto_renew_disabled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(SeoPackage::class, 'seo_package_id');
    }

    public function paymentProfile(): BelongsTo
    {
        return $this->belongsTo(PaymentProfile::class, 'payment_profile_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(SeoSubscriptionCharge::class, 'seo_package_subscription_id');
    }

    public function latestCharge(): HasOne
    {
        return $this->hasOne(SeoSubscriptionCharge::class, 'seo_package_subscription_id')->latestOfMany('period_start');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isCustomPricing(): bool
    {
        return $this->pricing_type === self::PRICING_CUSTOM;
    }

    public function isPastDue(): bool
    {
        return $this->payment_status === self::PAYMENT_STATUS_PAST_DUE;
    }

    /**
     * Name shown to admins and clients. Custom plans have no listed package,
     * and a deleted package keeps the name it had when it was activated.
     */
    public function displayPlanName(): string
    {
        return $this->plan_name
            ?: $this->package?->name
            ?: 'Custom SEO Package';
    }

    public function invoiceItemLabel(): string
    {
        return $this->invoice_item_label ?: self::DEFAULT_INVOICE_ITEM_LABEL;
    }

    /**
     * Billing day of the month. Falls back to the start date's day, so a
     * subscription that started on the 15th renews on the 15th.
     */
    public function anchorDay(): int
    {
        return $this->billing_anchor_day
            ?: (int) ($this->next_billing_date ?? $this->starts_at ?? now())->day;
    }

    /**
     * Last day of the billing period that starts on $period_start.
     */
    public function periodEndFor(CarbonInterface $period_start): Carbon
    {
        return self::advanceByOneMonth(Carbon::parse($period_start), $this->anchorDay())->subDay();
    }

    /**
     * Billing dates that have already passed without being paid: the
     * schedule only moves forward once a period is paid, so every billing
     * date from next_billing_date up to yesterday is still owed. Dates after
     * ends_at are never owed. Capped so a long forgotten subscription can
     * not loop forever.
     *
     * @return Carbon[]
     */
    public function overdueBillingDates(?CarbonInterface $today = null, int $limit = 36): array
    {
        if (! $this->next_billing_date) {
            return [];
        }

        $today  = Carbon::parse($today ?? now())->startOfDay();
        $anchor = $this->anchorDay();
        $date   = $this->next_billing_date->copy()->startOfDay();
        $dates  = [];

        while ($date->lt($today) && count($dates) < $limit) {
            if ($this->ends_at && $date->gt($this->ends_at)) {
                break;
            }

            $dates[] = $date->copy();
            $date    = self::advanceByOneMonth($date, $anchor);
        }

        return $dates;
    }

    /**
     * Same anchor day next month, clamped to the end of shorter months, so a
     * subscription anchored on the 31st bills on Feb 28 and on Mar 31 again.
     */
    public static function advanceByOneMonth(CarbonInterface $date, int $anchor_day): Carbon
    {
        $next = Carbon::parse($date)->startOfDay()->startOfMonth()->addMonthNoOverflow();

        return $next->setDay(min($anchor_day, $next->daysInMonth));
    }
}
