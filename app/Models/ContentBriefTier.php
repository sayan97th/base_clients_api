<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentBriefTier extends Model
{
    protected $table = 'content_brief_tiers';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'label',
        'turnaround_days',
        'price',
        'is_active',
        'is_most_popular',
        'max_quantity',
        'is_hidden',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price'           => 'decimal:2',
            'turnaround_days' => 'integer',
            'is_active'       => 'boolean',
            'is_most_popular' => 'boolean',
            'is_hidden'       => 'boolean',
        ];
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(ContentBriefOrderItem::class, 'tier_id');
    }
}
