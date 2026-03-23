<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderReportRow extends Model
{
    use HasUuids;

    public const STATUSES = ['pending', 'live', 'rejected'];

    protected $fillable = [
        'table_id',
        'order_placement_id',
        'position_index',
        'order_number',
        'link_type',
        'keyword',
        'landing_page',
        'exact_match',
        'request_date',
        'status',
        'live_link',
        'live_link_date',
        'dr',
    ];

    protected function casts(): array
    {
        return [
            'position_index' => 'integer',
            'exact_match'    => 'boolean',
            'request_date'   => 'date',
            'live_link_date' => 'date',
            'dr'             => 'integer',
        ];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(OrderReportTable::class, 'table_id');
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(LinkBuildingOrderPlacement::class, 'order_placement_id');
    }
}
