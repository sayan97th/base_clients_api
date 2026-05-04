<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentOptimizationIntakeRow extends Model
{
    use HasUuids;

    protected $fillable = [
        'item_id',
        'row_index',
        'primary_keyword',
        'secondary_keywords',
        'content_page_url',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ContentOptimizationOrderItem::class, 'item_id');
    }
}
