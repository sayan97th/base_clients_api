<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminResourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'title'           => $this->title,
            'description'     => $this->description,
            'category'        => $this->category,
            'status'          => $this->status,
            'organization_id' => $this->organization_id,
            'organization'    => $this->when(
                $this->organization_id !== null,
                fn () => $this->whenLoaded('organization', fn () => [
                    'id'   => $this->organization->id,
                    'name' => $this->organization->name,
                ]),
                null
            ),
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
            'files'           => ResourceFileResource::collection($this->whenLoaded('files')),
        ];
    }
}
