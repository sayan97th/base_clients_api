<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderReport extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LinkBuildingOrder::class, 'order_id');
    }

    public function tables(): HasMany
    {
        return $this->hasMany(OrderReportTable::class, 'order_report_id');
    }
}
