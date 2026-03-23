<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderReportTable extends Model
{
    use HasUuids;

    protected $fillable = [
        'report_id',
        'title',
        'description',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(OrderReport::class, 'report_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(OrderReportRow::class, 'table_id');
    }
}
