<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewContentOrderBilling extends Model
{
    use HasUuids;

    protected $table = 'new_content_order_billing';

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
        return $this->belongsTo(NewContentOrder::class, 'order_id');
    }
}
