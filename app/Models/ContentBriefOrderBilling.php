<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentBriefOrderBilling extends Model
{
    use HasUuids;

    protected $table = 'content_brief_order_billing';

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
        return $this->belongsTo(ContentBriefOrder::class, 'order_id');
    }
}
