<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderReportRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'order_number'         => $this->order_number,
            'link_type'            => $this->link_type,
            'keyword'              => $this->keyword,
            'landing_page'         => $this->landing_page,
            'exact_match'          => $this->exact_match,
            'request_date'         => $this->request_date?->format('Y-m-d'),
            'status'               => $this->status,
            'live_link'            => $this->live_link,
            'live_link_date'       => $this->live_link_date?->format('Y-m-d'),
            'dr'                   => $this->dr,
            'created_at'           => $this->created_at,
            'updated_at'           => $this->updated_at,
        ];
    }
}
