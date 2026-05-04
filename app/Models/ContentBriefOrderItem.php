<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentBriefOrderItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_id',
        'tier_id',
        'quantity',
        'unit_price',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'float',
            'subtotal'   => 'float',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ContentBriefOrder::class, 'order_id');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(ContentBriefTier::class, 'tier_id');
    }

    public function intakeRows(): HasMany
    {
        return $this->hasMany(ContentBriefIntakeRow::class, 'item_id')->orderBy('row_index');
    }
}
