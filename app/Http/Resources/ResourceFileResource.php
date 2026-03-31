<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResourceFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'file_type'    => $this->file_type,
            'size_bytes'   => $this->size_bytes,
            'download_url' => $this->download_url,
        ];
    }
}
