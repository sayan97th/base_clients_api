<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Coupon extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'description',
        'discount_type',
        'discount_value',
        'applies_to',
        'dr_tier_id',
        'minimum_purchase_amount',
        'starts_at',
        'expires_at',
        'usage_limit',
        'usage_per_user',
        'times_used',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_value'          => 'float',
            'minimum_purchase_amount' => 'float',
            'usage_limit'             => 'integer',
            'usage_per_user'          => 'integer',
            'times_used'              => 'integer',
            'is_active'               => 'boolean',
            'starts_at'               => 'datetime',
            'expires_at'              => 'datetime',
        ];
    }

    public function drTier(): BelongsTo
    {
        return $this->belongsTo(DrTier::class, 'dr_tier_id');
    }

    public function drTiers(): BelongsToMany
    {
        return $this->belongsToMany(
            DrTier::class,
            'coupon_dr_tiers',
            'coupon_id',
            'dr_tier_id'
        )->select(['dr_tiers.id', 'dr_tiers.label', 'dr_tiers.price_per_link', 'dr_tiers.is_active']);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(LinkBuildingOrder::class, 'coupon_id');
    }
}
