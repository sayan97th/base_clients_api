<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SmeAppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'service_type'   => $this->service_type,
            'event_uri'      => $this->event_uri,
            'invitee_uri'    => $this->invitee_uri,
            'selected_tiers' => $this->selected_tiers,
            'scheduled_at'   => $this->scheduled_at?->toIso8601String(),
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
