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
            'event_uri'      => $this->event_uri,
            'invitee_uri'    => $this->invitee_uri,
            'selected_tiers' => (object) ($this->selected_tiers ?? []),
            'service_type'   => $this->service_type,
            'status'         => $this->status,
            'scheduled_at'   => $this->scheduled_at?->toIso8601String(),
            'created_at'     => $this->created_at?->toIso8601String(),
            'updated_at'     => $this->updated_at?->toIso8601String(),
            'notes'          => $this->notes,
            'admin_notes'    => $this->admin_notes,
            'user'           => $this->whenLoaded('user', fn () => [
                'id'           => $this->user->id,
                'first_name'   => $this->user->first_name,
                'last_name'    => $this->user->last_name,
                'email'        => $this->user->email,
                'organization' => $this->user->organization?->name,
            ]),
        ];
    }
}
