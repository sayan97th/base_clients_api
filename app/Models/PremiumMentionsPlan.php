<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PremiumMentionsPlan extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'price_per_month',
        'total_placements',
        'exclusive_placements',
        'core_placements',
        'support_placements',
        'best_for',
        'tagline',
        'is_most_popular',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_per_month'      => 'float',
            'total_placements'     => 'integer',
            'exclusive_placements' => 'integer',
            'core_placements'      => 'integer',
            'support_placements'   => 'integer',
            'is_most_popular'      => 'boolean',
            'is_active'            => 'boolean',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PremiumMentionsOrder::class, 'plan_id');
    }
}
