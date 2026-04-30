<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduledCallAppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'event_uri'           => $this->event_uri,
            'invitee_uri'         => $this->invitee_uri,
            'status'              => $this->status,
            'scheduled_at'        => $this->scheduled_at?->toIso8601String(),
            'notes'               => $this->notes,
            'cancellation_reason' => $this->cancellation_reason,
            'reschedule_reason'   => $this->reschedule_reason,
            'preferred_dates'     => $this->preferred_dates,
            'created_at'          => $this->created_at?->toIso8601String(),
            'updated_at'          => $this->updated_at?->toIso8601String(),
        ];
    }
}
