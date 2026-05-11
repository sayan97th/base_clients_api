<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentBriefIntakeRow extends Model
{
    use HasUuids;

    protected $fillable = [
        'item_id',
        'row_index',
        'primary_keyword',
        'secondary_keywords',
        'content_page_url',
        'notes',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ContentBriefOrderItem::class, 'item_id');
    }
}
