<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Discount extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'description',
        'discount_type',
        'discount_rate',
        'min_quantity',
        'applies_to',
        'is_active',
    ];

    protected $casts = [
        'discount_rate' => 'float',
        'min_quantity'  => 'integer',
        'is_active'     => 'boolean',
    ];

    public function drTiers(): BelongsToMany
    {
        return $this->belongsToMany(
            DrTier::class,
            'discount_dr_tiers',
            'discount_id',
            'dr_tier_id'
        )->select(['dr_tiers.id', 'dr_tiers.label', 'dr_tiers.price_per_link', 'dr_tiers.is_active']);
    }
}
