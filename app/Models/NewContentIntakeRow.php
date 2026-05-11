<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewContentIntakeRow extends Model
{
    use HasUuids;

    public const STATUSES         = ['pending', 'in_progress', 'completed', 'cancelled'];
    public const CONTENT_TYPES    = ['Blog Article', 'Product Page', 'Home Page', 'About Us Page', 'Other'];

    protected $fillable = [
        'item_id',
        'row_index',
        'keyword_phrase',
        'secondary_keywords',
        'type_of_content',
        'notes',
        'status',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(NewContentOrderItem::class, 'item_id');
    }
}
