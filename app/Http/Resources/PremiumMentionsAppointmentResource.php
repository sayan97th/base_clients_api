<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PremiumMentionsAppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'event_uri'    => $this->event_uri,
            'invitee_uri'  => $this->invitee_uri,
            'plan_id'      => $this->plan_id,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
