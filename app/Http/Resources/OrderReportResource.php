<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'order_id'   => $this->order_id,
            'sent_at'    => $this->sent_at,
            'tables'     => OrderReportTableResource::collection($this->whenLoaded('tables')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
