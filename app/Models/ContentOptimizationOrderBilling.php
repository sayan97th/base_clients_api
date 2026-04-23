<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentOptimizationOrderBilling extends Model
{
    use HasUuids;

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
        return $this->belongsTo(ContentOptimizationOrder::class, 'order_id');
    }
}
