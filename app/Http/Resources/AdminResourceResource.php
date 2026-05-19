<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminResourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'title'            => $this->title,
            'description'      => $this->description,
            'category'         => $this->category,
            'status'           => $this->status,
            'is_hidden'        => (bool) $this->is_hidden,
            'organization_id'  => $this->organization_id,
            'organization'     => $this->when(
                $this->organization_id !== null,
                fn () => $this->whenLoaded('organization', fn () => [
                    'id'   => $this->organization->id,
                    'name' => $this->organization->name,
                ]),
                null
            ),
            'assigned_clients' => $this->whenLoaded('clients', fn () =>
                $this->clients->map(fn ($u) => [
                    'id'    => $u->id,
                    'name'  => trim("{$u->first_name} {$u->last_name}"),
                    'email' => $u->email,
                ])->values()
            ),
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
            'files'            => ResourceFileResource::collection($this->whenLoaded('files')),
        ];
    }
}
