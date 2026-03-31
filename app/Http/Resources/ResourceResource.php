<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'description' => $this->description,
            'category'    => $this->category,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
            'files'       => ResourceFileResource::collection($this->whenLoaded('files')),
        ];
    }
}
