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
        'order_placement_id',
        'status',
        'live_link',
        'live_link_date',
        'dr',
    ];

    protected function casts(): array
    {
        return [
            'live_link_date' => 'date:Y-m-d',
            'dr'             => 'integer',
        ];
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(LinkBuildingOrderPlacement::class, 'order_placement_id');
    }
}
