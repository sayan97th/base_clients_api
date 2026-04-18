<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoSubscription extends Model
{
    use HasUuids;

    const STATUSES = ['pending', 'active', 'processing', 'cancelled'];

    protected $fillable = [
        'user_id',
        'package_id',
        'status',
        'total_amount',
        'payment_method_id',
        'billing_company',
        'billing_address',
        'billing_city',
        'billing_state',
        'billing_country',
        'billing_postal_code',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(SeoPackage::class, 'package_id');
    }
}
