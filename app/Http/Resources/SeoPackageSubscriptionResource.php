<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeoPackageSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'status'     => $this->status,
            'starts_at'  => $this->starts_at?->toIso8601String(),
            'ends_at'    => $this->ends_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'package'    => $this->whenLoaded('package', fn () => [
                'id'              => $this->package->id,
                'name'            => $this->package->name,
                'slug'            => $this->package->slug,
                'price_per_month' => $this->package->price_per_month,
            ]),
        ];
    }
}
