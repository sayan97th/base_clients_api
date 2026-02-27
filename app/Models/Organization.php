<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Organization extends Model
{
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

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_role')
            ->withPivot('role_id');
    }
}
