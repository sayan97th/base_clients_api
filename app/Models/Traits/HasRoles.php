<?php

namespace App\Models\Traits;

use App\Models\Organization;
use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasRoles
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role')
            ->withPivot('organization_id');
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'user_role')
            ->withPivot('role_id')
            ->distinct();
    }

    public function hasRole(string|array $roles, ?int $organizationId = null): bool
    {
        $userRoles = $this->roles;

        if (is_string($roles)) {
            $roles = [$roles];
        }

        foreach ($roles as $role) {
            $match = $userRoles->first(function ($userRole) use ($role, $organizationId) {
                if ($userRole->name !== $role) {
                    return false;
                }

                if ($organizationId !== null) {
                    return $userRole->pivot->organization_id === $organizationId;
                }

                return true;
            });

            if ($match) {
                return true;
            }
        }

        return false;
    }

    public function hasGlobalRole(string $role): bool
    {
        return $this->roles
            ->where('name', $role)
            ->whereNull('pivot.organization_id')
            ->isNotEmpty();
    }

    public function hasPermission(string $permission, ?int $organizationId = null): bool
    {
        if ($this->hasGlobalRole('super_admin')) {
            return true;
        }

        $query = $this->roles()
            ->whereHas('permissions', function ($q) use ($permission) {
                $q->where('name', $permission);
            });

        if ($organizationId !== null) {
            $query->wherePivot('organization_id', $organizationId);
        }

        return $query->exists();
    }

    public function assignRole(string $roleName, ?int $organizationId = null): void
    {
        $role = Role::where('name', $roleName)->firstOrFail();

        $exists = $this->roles()
            ->where('role_id', $role->id)
            ->wherePivot('organization_id', $organizationId)
            ->exists();

        if (!$exists) {
            $this->roles()->attach($role->id, ['organization_id' => $organizationId]);
        }
    }

    public function removeRole(string $roleName, ?int $organizationId = null): void
    {
        $role = Role::where('name', $roleName)->firstOrFail();

        $this->roles()
            ->wherePivot('organization_id', $organizationId)
            ->detach($role->id);
    }

    public function syncRoles(array $roleNames, ?int $organizationId = null): void
    {
        $this->roles()
            ->wherePivot('organization_id', $organizationId)
            ->detach();

        foreach ($roleNames as $roleName) {
            $this->assignRole($roleName, $organizationId);
        }
    }

    public function getRolesForOrganization(int $organizationId): array
    {
        return $this->roles()
            ->wherePivot('organization_id', $organizationId)
            ->pluck('name')
            ->toArray();
    }

    public function getGlobalRoles(): array
    {
        return $this->roles()
            ->wherePivotNull('organization_id')
            ->pluck('name')
            ->toArray();
    }

    public function getAllPermissions(?int $organizationId = null): array
    {
        $query = $this->roles()->with('permissions');

        if ($organizationId !== null) {
            $query->where(function ($q) use ($organizationId) {
                $q->wherePivot('organization_id', $organizationId)
                    ->orWherePivotNull('organization_id');
            });
        }

        return $query->get()
            ->pluck('permissions')
            ->flatten()
            ->pluck('name')
            ->unique()
            ->values()
            ->toArray();
    }
}
