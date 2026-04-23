<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentOptimizationTier extends Model
{
    use HasFactory;

    protected $table = 'content_optimization_tiers';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'label',
        'word_count_range',
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
            'is_active'       => 'boolean',
            'is_most_popular' => 'boolean',
            'is_hidden'       => 'boolean',
        ];
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(ContentOptimizationOrderItem::class, 'tier_id');
    }
}
