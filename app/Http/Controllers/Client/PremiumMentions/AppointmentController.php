<?php

namespace App\Http\Controllers\Client\PremiumMentions;

use App\Http\Controllers\Controller;
use App\Http\Requests\PremiumMentions\StorePremiumMentionsAppointmentRequest;
use App\Http\Resources\PremiumMentionsAppointmentResource;
use App\Models\PremiumMentionsAppointment;
use Illuminate\Http\JsonResponse;

class AppointmentController extends Controller
{
    public function store(StorePremiumMentionsAppointmentRequest $request): JsonResponse
    {
        $existing = PremiumMentionsAppointment::where('event_uri', $request->event_uri)->first();

        if ($existing) {
            return response()->json(
                ['data' => new PremiumMentionsAppointmentResource($existing)],
                200
            );
        }

        $appointment = PremiumMentionsAppointment::create([
            'user_id'      => $request->user()->id,
            'plan_id'      => $request->plan_id,
            'event_uri'    => $request->event_uri,
            'invitee_uri'  => $request->invitee_uri,
            'scheduled_at' => now(),
        ]);

        return response()->json(
            ['data' => new PremiumMentionsAppointmentResource($appointment)],
            201
        );
    }

    public function show(int $id): JsonResponse
    {
        $appointment = PremiumMentionsAppointment::find($id);

        if (!$appointment || $appointment->user_id !== auth()->id()) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        return response()->json(
            ['data' => new PremiumMentionsAppointmentResource($appointment)]
        );
    }
}
