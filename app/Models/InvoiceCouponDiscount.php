<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceCouponDiscount extends Model
{
    use HasUuids;

    protected $fillable = [
        'invoice_id',
        'code',
        'name',
        'discount_type',
        'discount_value',
        'discount_amount',
    ];

    protected function casts(): array
    {
        return [
            'discount_value'  => 'float',
            'discount_amount' => 'float',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
