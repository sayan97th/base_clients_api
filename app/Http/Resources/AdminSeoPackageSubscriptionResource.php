<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSeoPackageSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'status'       => $this->status,
            'starts_at'    => $this->starts_at?->toIso8601String(),
            'ends_at'      => $this->ends_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'notes'        => $this->notes,
            'created_at'   => $this->created_at?->toIso8601String(),
            'updated_at'   => $this->updated_at?->toIso8601String(),
            'user'         => $this->whenLoaded('user', fn () => [
                'id'           => $this->user->id,
                'first_name'   => $this->user->first_name,
                'last_name'    => $this->user->last_name,
                'email'        => $this->user->email,
                'organization' => $this->user->organization?->name,
            ]),
            'package'      => $this->whenLoaded('package', fn () => [
                'id'              => $this->package->id,
                'name'            => $this->package->name,
                'slug'            => $this->package->slug,
                'price_per_month' => $this->package->price_per_month,
            ]),
        ];
    }
}
