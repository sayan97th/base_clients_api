<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserWithRolesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'first_name'        => $this->first_name,
            'last_name'         => $this->last_name,
            'email'             => $this->email,
            'business_email'    => $this->business_email,
            'phone'             => $this->phone,
            'job_title'         => $this->job_title,
            'profile_photo_url' => $this->profile_photo_url,
            'organization_id'   => $this->organization_id,
            'email_verified_at' => $this->email_verified_at,
            'last_login_at'     => $this->last_login_at,
            'is_active'         => $this->is_active,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
            'roles'             => $this->roles->map(fn ($role) => [
                'id'           => $role->id,
                'name'         => $role->name,
                'display_name' => $role->display_name,
            ])->values(),
            'organization'      => $this->organization
                ? ['id' => $this->organization->id, 'name' => $this->organization->name]
                : null,
        ];
    }
}
