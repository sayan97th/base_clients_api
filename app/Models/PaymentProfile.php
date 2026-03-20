<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentProfile extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'stripe_payment_method_id',
        'card_brand',
        'last_four',
        'expiry_month',
        'expiry_year',
        'cardholder_name',
        'billing_address_line1',
        'billing_address_city',
        'billing_address_state',
        'billing_address_postal',
        'billing_address_country',
        'billing_address_company',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
