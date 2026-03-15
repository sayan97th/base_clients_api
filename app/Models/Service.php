<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Service extends Model
{
    use HasUuids, SoftDeletes;

    const CATEGORIES    = ['link_building', 'content', 'seo', 'other'];
    const PRICING_MODELS = ['tiered', 'fixed', 'per_unit', 'subscription', 'custom'];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'category',
        'pricing_model',
        'base_price',
        'is_active',
        'is_featured',
    ];

    protected $appends = ['orders_count', 'revenue_total'];

    protected function casts(): array
    {
        return [
            'base_price'  => 'float',
            'is_active'   => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Service $service) {
            if (empty($service->slug)) {
                $service->slug = Str::slug($service->name);
            }
        });
    }

    public function getOrdersCountAttribute(): int
    {
        // Extend this accessor once a service_id FK is added to orders
        return 0;
    }

    public function getRevenueTotalAttribute(): float
    {
        // Extend this accessor once a service_id FK is added to orders
        return 0.0;
    }
}
