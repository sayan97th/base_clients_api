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

    const STATUSES        = ['paid', 'void'];
    const CURRENCY_TYPES  = ['usd', 'credits'];
    const PAYMENT_METHODS = ['Account Balance', 'Credit Card'];

    protected $fillable = [
        'unique_id',
        'invoice_number',
        'user_id',
        'order_id',
        'status',
        'payment_method',
        'currency_type',
        'subtotal_amount',
        'discount_amount',
        'total_amount',
        'credit_amount',
        'date_issued',
        'date_due',
        'date_paid',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_amount' => 'float',
            'discount_amount' => 'float',
            'total_amount'    => 'float',
            'credit_amount'   => 'float',
            'date_issued'     => 'datetime',
            'date_due'        => 'datetime',
            'date_paid'       => 'datetime',
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
}
