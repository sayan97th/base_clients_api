<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLineItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'invoice_id',
        'order_id',
        'item_name',
        'product_type',
        'description',
        'price',
        'quantity',
        'discount_percent',
        'item_total',
    ];

    protected function casts(): array
    {
        return [
            'price'            => 'float',
            'quantity'         => 'integer',
            'discount_percent' => 'float',
            'item_total'       => 'float',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
