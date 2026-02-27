<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingAddress extends Model
{
    protected $fillable = [
        'user_id',
        'company',
        'tax_id',
        'address',
        'address_line_2',
        'city',
        'state_province',
        'country',
        'postal_code',
        'billing_email',
        'billing_phone',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
