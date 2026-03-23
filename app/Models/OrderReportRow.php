<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderReportRow extends Model
{
    use HasUuids;

    const STATUSES = ['pending', 'live', 'rejected'];

    protected $fillable = [
        'order_report_table_id',
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
            'exact_match'    => 'boolean',
            'request_date'   => 'date:Y-m-d',
            'live_link_date' => 'date:Y-m-d',
            'dr'             => 'integer',
        ];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(OrderReportTable::class, 'order_report_table_id');
    }
}
