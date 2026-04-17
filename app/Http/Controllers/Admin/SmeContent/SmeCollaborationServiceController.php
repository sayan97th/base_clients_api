<?php

namespace App\Http\Controllers\Admin\SmeContent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SmeContent\StoreSmeServiceRequest;
use App\Http\Requests\Admin\SmeContent\UpdateSmeServiceRequest;
use App\Models\SmeCollaborationTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class SmeCollaborationServiceController extends Controller
{
    public function index(): JsonResponse
    {
        $services = SmeCollaborationTier::orderBy('sort_order')->get();

        return response()->json(['data' => $services->map(fn($s) => $this->formatService($s))->values()]);
    }

    public function store(StoreSmeServiceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['tier_key'] = $this->uniqueTierKey(SmeCollaborationTier::class, $data['label']);

        $service = SmeCollaborationTier::create($data);

        return response()->json(['data' => $this->formatService($service)], 201);
    }

    public function update(UpdateSmeServiceRequest $request, string $service_id): JsonResponse
    {
        $service = SmeCollaborationTier::where('tier_key', $service_id)->first();

        if (!$service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $service->update($request->validated());

        return response()->json(['data' => $this->formatService($service->fresh())]);
    }

    public function destroy(string $service_id): JsonResponse
    {
        $service = SmeCollaborationTier::where('tier_key', $service_id)->first();

        if (!$service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $service->delete();

        return response()->json(null, 204);
    }

    private function formatService(SmeCollaborationTier $service): array
    {
        return [
            'id'          => $service->tier_key,
            'label'       => $service->label,
            'description' => $service->description,
            'price'       => (float) $service->price,
            'is_active'   => $service->is_active,
            'created_at'  => $service->created_at?->toIso8601String(),
            'updated_at'  => $service->updated_at?->toIso8601String(),
        ];
    }

    private function uniqueTierKey(string $model, string $label): string
    {
        $base = Str::slug($label, '_');
        $key  = $base;
        $i    = 1;

        while ($model::where('tier_key', $key)->exists()) {
            $key = $base . '_' . $i++;
        }

        return $key;
    }
}
