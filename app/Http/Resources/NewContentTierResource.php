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
            'id' => $this->tier_id,
            'label' => $this->label,
            'turnaround_time' => $this->turnaround_time,
            'price' => (int) $this->price,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
