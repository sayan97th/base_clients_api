<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LinkBuildingOrderItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_id',
        'dr_tier_id',
        'quantity',
        'unit_price',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'quantity'   => 'integer',
            'unit_price' => 'float',
            'subtotal'   => 'float',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LinkBuildingOrder::class, 'order_id');
    }

    public function drTier(): BelongsTo
    {
        return $this->belongsTo(DrTier::class, 'dr_tier_id');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(LinkBuildingOrderPlacement::class, 'order_item_id');
    }
}
