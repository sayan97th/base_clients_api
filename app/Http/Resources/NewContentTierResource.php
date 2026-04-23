<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NewContentTierResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'label'           => $this->label,
            'turnaround_time' => $this->turnaround_time,
            'price'           => (float) $this->price,
            'is_active'       => $this->is_active,
            'is_most_popular' => $this->is_most_popular,
            'max_quantity'    => $this->max_quantity,
            'is_hidden'       => $this->is_hidden,
            'sort_order'      => $this->sort_order,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }
}
