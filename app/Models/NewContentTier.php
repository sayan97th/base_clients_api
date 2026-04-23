<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewContentTier extends Model
{
    use HasFactory;

    protected $table = 'new_content_tiers';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'label',
        'turnaround_time',
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
            'price'          => 'decimal:2',
            'is_active'      => 'boolean',
            'is_most_popular' => 'boolean',
            'is_hidden'      => 'boolean',
        ];
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(NewContentOrderItem::class, 'tier_id');
    }
}
