<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


class Invoice extends Model
{
    use HasUuids;

    public const STATUSES        = ['unpaid', 'paid', 'overdue', 'refund', 'void'];
    public const CURRENCY_TYPES  = ['usd', 'credits'];
    public const PAYMENT_METHODS = ['Account Balance', 'Credit Card'];

    protected $fillable = [
        'unique_id',
        'invoice_number',
        'user_id',
        'order_id',
        'session_id',
        'session_title',
        'status',
        'payment_method',
        'payment_intent_id',
        'currency_type',
        'subtotal_amount',
        'discount_amount',
        'discount_type',
        'total_amount',
        'credit_amount',
        'notes',
        'share_key',
        'sharing_enabled',
        'date_issued',
        'date_due',
        'date_paid',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_amount'  => 'float',
            'discount_amount'  => 'float',
            'total_amount'     => 'float',
            'credit_amount'    => 'float',
            'sharing_enabled'  => 'boolean',
            'date_issued'      => 'datetime',
            'date_due'         => 'datetime',
            'date_paid'        => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LinkBuildingOrder::class, 'order_id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(InvoiceLineItem::class);
    }

    public function billedTo(): HasOne
    {
        return $this->hasOne(InvoiceBilledTo::class);
    }

    public function couponDiscounts(): HasMany
    {
        return $this->hasMany(InvoiceCouponDiscount::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(InvoiceHistory::class);
    }
}
