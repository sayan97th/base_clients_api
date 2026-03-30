<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NewsPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'type'           => $this->type,
            'status'         => $this->status,
            'title'          => $this->title,
            'subtitle'       => $this->subtitle,
            'description'    => $this->description,
            'discount_value' => $this->discount_value,
            'discount_label' => $this->discount_label,
            'coupon_id'      => $this->coupon_id,
            'coupon_code'    => $this->coupon?->code,
            'starts_at'      => $this->starts_at?->toDateString(),
            'ends_at'        => $this->ends_at?->toDateString(),
            'image_url'      => $this->image_url,
            'thumbnail_url'  => $this->thumbnail_url,
            'cta_text'       => $this->cta_text,
            'cta_url'        => $this->cta_url,
            'tags'           => $this->tags ?? [],
            'is_featured'    => (bool) $this->is_featured,
            'sort_order'     => (int) $this->sort_order,
            'created_at'     => $this->created_at,
            'updated_at'     => $this->updated_at,
        ];
    }
}
