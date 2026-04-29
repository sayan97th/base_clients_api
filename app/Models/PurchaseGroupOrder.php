<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseGroupOrder extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'purchase_group_id',
        'order_id',
        'product_type',
        'total_amount',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'float',
        ];
    }

    public function purchaseGroup(): BelongsTo
    {
        return $this->belongsTo(PurchaseGroup::class, 'purchase_group_id', 'purchase_group_id');
    }
}
