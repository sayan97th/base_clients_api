<?php

namespace App\Models;

use App\Models\Traits\HasRoles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'business_email',
        'password',
        'phone',
        'job_title',
        'profile_photo_url',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function billingAddress(): HasOne
    {
        return $this->hasOne(BillingAddress::class);
    }

    public function preference(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        $globalRoles = $this->getGlobalRoles();

        $organizations = $this->organizations->map(function ($org) {
            return [
                'id' => $org->id,
                'slug' => $org->slug,
                'roles' => $this->getRolesForOrganization($org->id),
            ];
        })->unique('id')->values();

        return [
            'global_roles' => $globalRoles,
            'organizations' => $organizations,
        ];
    }
}
