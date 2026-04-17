<?php

namespace App\Http\Controllers\Client\SmeAppointment;

use App\Http\Controllers\Controller;
use App\Http\Requests\SmeAppointment\StoreSmeAppointmentRequest;
use App\Http\Resources\SmeAppointmentResource;
use App\Models\SmeAppointment;
use App\Models\SmeAuthoredTier;
use App\Models\SmeCollaborationTier;
use App\Models\SmeEnhancedTier;
use Illuminate\Http\JsonResponse;

class SmeAppointmentController extends Controller
{
    public function index(): JsonResponse
    {
        $appointments = SmeAppointment::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => SmeAppointmentResource::collection($appointments),
        ]);
    }

    public function store(StoreSmeAppointmentRequest $request): JsonResponse
    {
        $tiers = $request->service_type
            ? $this->enrichTiers($request->service_type, $request->selected_tiers)
            : $request->selected_tiers;

        $appointment = SmeAppointment::create([
            'user_id'        => $request->user()->id,
            'service_type'   => $request->service_type,
            'event_uri'      => $request->event_uri,
            'invitee_uri'    => $request->invitee_uri,
            'selected_tiers' => $tiers,
            'scheduled_at'   => null,
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

    public function destroy(int $id): JsonResponse
    {
        $appointment = SmeAppointment::findOrFail($id);

        if ($appointment->user_id !== auth()->id()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $appointment->delete();

        return response()->json(null, 204);
    }

    private function enrichTiers(string $serviceType, array $selectedTiers): array
    {
        $tierModel = match ($serviceType) {
            'authored'      => SmeAuthoredTier::class,
            'collaboration' => SmeCollaborationTier::class,
            'enhanced'      => SmeEnhancedTier::class,
        };

        $tierKeys = array_keys($selectedTiers);
        $tiers    = $tierModel::whereIn('tier_key', $tierKeys)->get()->keyBy('tier_key');

        $enriched = [];
        foreach ($selectedTiers as $tierKey => $quantity) {
            $tier       = $tiers->get($tierKey);
            $enriched[] = [
                'tier_key'    => $tierKey,
                'quantity'    => $quantity,
                'label'       => $tier?->label,
                'description' => $tier?->description,
                'price'       => $tier?->price,
            ];
        }

        return $enriched;
    }
}
