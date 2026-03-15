<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DrTier extends Model
{
    use SoftDeletes;
    protected $table = 'dr_tiers';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'dr_label',
        'traffic_range',
        'word_count',
        'price_per_link',
        'is_most_popular',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'word_count'      => 'integer',
            'price_per_link'  => 'float',
            'is_most_popular' => 'boolean',
            'is_active'       => 'boolean',
        ];
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(LinkBuildingOrderItem::class, 'dr_tier_id');
    }
}
