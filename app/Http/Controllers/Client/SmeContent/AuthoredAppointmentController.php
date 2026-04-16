<?php

namespace App\Http\Controllers\Client\SmeContent;

use App\Http\Controllers\Controller;
use App\Http\Requests\SmeContent\StoreAuthoredAppointmentRequest;
use App\Models\SmeAppointment;
use Illuminate\Http\JsonResponse;

class AuthoredAppointmentController extends Controller
{
    public function index(): JsonResponse
    {
        $appointments = SmeAppointment::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $appointments->map(fn ($a) => $this->formatAppointment($a)),
        ]);
    }

    public function store(StoreAuthoredAppointmentRequest $request): JsonResponse
    {
        $appointment = SmeAppointment::create([
            'user_id'        => $request->user()->id,
            'event_uri'      => $request->event_uri,
            'invitee_uri'    => $request->invitee_uri,
            'selected_tiers' => $request->selected_tiers,
            'scheduled_at'   => now(),
        ]);

        return response()->json(['data' => $this->formatAppointment($appointment)], 201);
    }

    public function show(int $id): JsonResponse
    {
        $appointment = SmeAppointment::find($id);

        if (!$appointment || $appointment->user_id !== auth()->id()) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        return response()->json(['data' => $this->formatAppointment($appointment)]);
    }

    public function destroy(int $id): JsonResponse
    {
        $appointment = SmeAppointment::find($id);

        if (!$appointment || $appointment->user_id !== auth()->id()) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        $appointment->delete();

        return response()->json(null, 204);
    }

    private function formatAppointment(SmeAppointment $appointment): array
    {
        return [
            'id'             => $appointment->id,
            'event_uri'      => $appointment->event_uri,
            'invitee_uri'    => $appointment->invitee_uri,
            'selected_tiers' => $appointment->selected_tiers,
            'scheduled_at'   => $appointment->scheduled_at?->toIso8601String(),
            'created_at'     => $appointment->created_at,
            'updated_at'     => $appointment->updated_at,
        ];
    }
}
