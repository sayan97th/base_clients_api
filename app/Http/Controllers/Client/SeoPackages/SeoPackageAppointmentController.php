<?php

namespace App\Http\Controllers\Client\SeoPackages;

use App\Http\Controllers\Controller;
use App\Http\Requests\SeoPackages\StoreSeoPackageAppointmentRequest;
use App\Http\Resources\SeoPackageAppointmentResource;
use App\Models\SeoPackage;
use App\Models\SeoPackageAppointment;
use Illuminate\Http\JsonResponse;

class SeoPackageAppointmentController extends Controller
{
    public function store(StoreSeoPackageAppointmentRequest $request): JsonResponse
    {
        $package = SeoPackage::where('id', $request->package_id)
            ->where('is_active', true)
            ->first();

        if (! $package) {
            return response()->json(['message' => 'The selected package is invalid or inactive.'], 422);
        }

        $existing = SeoPackageAppointment::where('event_uri', $request->event_uri)->first();

        if ($existing) {
            return response()->json(['data' => new SeoPackageAppointmentResource($existing)], 200);
        }

        $appointment = SeoPackageAppointment::create([
            'user_id'        => $request->user()->id,
            'seo_package_id' => $request->package_id,
            'event_uri'      => $request->event_uri,
            'invitee_uri'    => $request->invitee_uri,
            'status'         => 'pending',
        ]);

        return response()->json(['data' => new SeoPackageAppointmentResource($appointment)], 201);
    }

    public function show(int $id): JsonResponse
    {
        $appointment = SeoPackageAppointment::find($id);

        if (! $appointment) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        if ($appointment->user_id !== auth()->id()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['data' => new SeoPackageAppointmentResource($appointment)]);
    }
}
