<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PremiumMentionsOrderBilling extends Model
{
    protected $fillable = [
        'order_id',
        'company',
        'address',
        'city',
        'state',
        'country',
        'postal_code',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(PremiumMentionsOrder::class, 'order_id');
    }
}
