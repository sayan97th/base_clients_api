<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinkBuildingOrderPlacement extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_item_id',
        'row_index',
        'keyword',
        'landing_page',
        'exact_match',
    ];

    protected function casts(): array
    {
        return [
            'row_index'   => 'integer',
            'exact_match' => 'boolean',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(LinkBuildingOrderItem::class, 'order_item_id');
    }
}
