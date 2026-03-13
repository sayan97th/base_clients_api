<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends Model
{
    const CARD_BRANDS = ['visa', 'mastercard', 'amex', 'discover', 'diners', 'jcb', 'unionpay', 'unknown'];

    protected $fillable = [
        'user_id',
        'stripe_payment_method_id',
        'card_brand',
        'card_last_four',
        'card_exp_month',
        'card_exp_year',
        'cardholder_name',
        'billing_zip',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default'      => 'boolean',
            'card_exp_month'  => 'integer',
            'card_exp_year'   => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getCardSummaryAttribute(): string
    {
        return ucfirst($this->card_brand) . ' ending in ' . $this->card_last_four;
    }

    public function getExpiryAttribute(): string
    {
        return str_pad($this->card_exp_month, 2, '0', STR_PAD_LEFT) . '/' . $this->card_exp_year;
    }

    public function isExpired(): bool
    {
        $now = now();
        if ($this->card_exp_year < $now->year) {
            return true;
        }
        if ($this->card_exp_year === $now->year && $this->card_exp_month < $now->month) {
            return true;
        }
        return false;
    }
}
