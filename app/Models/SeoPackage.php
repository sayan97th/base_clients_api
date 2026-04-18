<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoPackage extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'slug',
        'price_per_month',
        'best_for',
        'features',
        'is_most_popular',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_per_month' => 'float',
            'features'        => 'array',
            'is_most_popular' => 'boolean',
            'is_active'       => 'boolean',
            'sort_order'      => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(SeoSubscription::class, 'package_id');
    }
}
