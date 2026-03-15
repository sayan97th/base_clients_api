<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Organization extends Model
{
    /**
     * The default organization slug (BASE Search Marketing).
     * All new users are automatically assigned to this organization.
     */
    const DEFAULT_SLUG = 'base-search-marketing';

    /**
     * Retrieve the default organization.
     * Returns null if the organization has not been seeded yet.
     */
    public static function findDefault(): ?self
    {
        return static::where('slug', self::DEFAULT_SLUG)->first();
    }


    protected $fillable = [
        'name',
        'slug',
        'description',
        'website',
        'contact_email',
        'contact_phone',
        'contact_link',
        'logo_light',
        'logo_dark',
        'icon_light',
        'icon_dark',
        'mobile_app_icon',
        'primary_color',
        'accent_color',
        'timezone',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $organization) {
            if (empty($organization->slug)) {
                $organization->slug = Str::slug($organization->name);
            }
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }
}
