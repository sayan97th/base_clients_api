<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'email'       => $this->email,
            'role'        => $this->role,
            'token'       => $this->token,
            'invited_by'  => $this->invited_by,
            'accepted_at' => $this->accepted_at,
            'expires_at'  => $this->expires_at,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
            'inviter'     => $this->whenLoaded('inviter', fn () => [
                'id'         => $this->inviter->id,
                'first_name' => $this->inviter->first_name,
                'last_name'  => $this->inviter->last_name,
                'email'      => $this->inviter->email,
            ]),
        ];
    }
}
