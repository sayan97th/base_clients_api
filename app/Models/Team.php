<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Team extends Model
{
    const TEAM_PERMISSIONS = [
        'tickets.view',
        'tickets.manage',
        'calls.view',
        'calls.manage',
        'subscriptions.view',
        'subscriptions.manage',
        'payments.view',
        'team_members.view',
        'team_members.manage',
        'reports.view',
        'reports.export',
        'clients.view',
        'clients.manage',
    ];

    const TEAM_ROLES = ['owner', 'manager', 'member'];

    protected $fillable = [
        'organization_id',
        'created_by',
        'name',
        'slug',
        'description',
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
        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = Str::slug($team->name);
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')
            ->withPivot(['role', 'permissions', 'joined_at']);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function pendingInvitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class)
            ->where('status', 'pending')
            ->where('expires_at', '>', now());
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->exists();
    }

    public function getMemberPermissions(User $user): array
    {
        $pivot = $this->members()->where('user_id', $user->id)->first()?->pivot;

        if (!$pivot) {
            return [];
        }

        $permissions = $pivot->permissions;

        if (is_string($permissions)) {
            return json_decode($permissions, true) ?? [];
        }

        return $permissions ?? [];
    }

    public function getMemberRole(User $user): ?string
    {
        return $this->members()->where('user_id', $user->id)->first()?->pivot?->role;
    }
}
