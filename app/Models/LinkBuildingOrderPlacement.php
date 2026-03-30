<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LinkBuildingOrderPlacement extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_item_id',
        'row_index',
        'keyword',
        'landing_page',
        'exact_match',
        'live_link',
        'dr',
        'completed_date',
    ];

    protected function casts(): array
    {
        return [
            'row_index'      => 'integer',
            'exact_match'    => 'boolean',
            'dr'             => 'integer',
            'completed_date' => 'datetime',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(LinkBuildingOrderItem::class, 'order_item_id');
    }

    public function reportRow(): HasOne
    {
        return $this->hasOne(OrderReportRow::class, 'order_placement_id');
    }
}
