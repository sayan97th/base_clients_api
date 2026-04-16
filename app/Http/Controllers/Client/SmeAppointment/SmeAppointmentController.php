<?php

namespace App\Http\Controllers\Client\SmeAppointment;

use App\Http\Controllers\Controller;
use App\Http\Requests\SmeAppointment\StoreSmeAppointmentRequest;
use App\Http\Resources\SmeAppointmentResource;
use App\Models\SmeAppointment;
use Illuminate\Http\JsonResponse;

class SmeAppointmentController extends Controller
{
    public function store(StoreSmeAppointmentRequest $request): JsonResponse
    {
        $appointment = SmeAppointment::create([
            'user_id'        => $request->user()->id,
            'event_uri'      => $request->event_uri,
            'invitee_uri'    => $request->invitee_uri,
            'selected_tiers' => $request->selected_tiers,
            'scheduled_at'   => now(),
        ]);

        return response()->json(
            ['data' => new SmeAppointmentResource($appointment)],
            201
        );
    }

    public function show(int $id): JsonResponse
    {
        $appointment = SmeAppointment::findOrFail($id);

        if ($appointment->user_id !== auth()->id()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(
            ['data' => new SmeAppointmentResource($appointment)]
        );
    }
}
