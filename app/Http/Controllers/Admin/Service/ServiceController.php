<?php

namespace App\Http\Controllers\Admin\Service;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Service\StoreServiceRequest;
use App\Http\Requests\Admin\Service\UpdateServiceRequest;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ServiceController extends Controller
{
    /**
     * GET /api/admin/services
     */
    public function index(): JsonResponse
    {
        $services = Service::orderBy('created_at', 'desc')->get();

        return response()->json($services->map(fn (Service $service) => $this->formatService($service)));
    }

    /**
     * GET /api/admin/services/{id}
     */
    public function show(string $id): JsonResponse
    {
        $service = Service::find($id);

        if (!$service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        return response()->json($this->formatService($service));
    }

    /**
     * POST /api/admin/services
     */
    public function store(StoreServiceRequest $request): JsonResponse
    {
        $data         = $request->validated();
        $data['slug'] = Str::slug($data['name']);

        $service = Service::create($data);

        return response()->json($this->formatService($service), 201);
    }

    /**
     * PATCH /api/admin/services/{id}
     */
    public function update(UpdateServiceRequest $request, string $id): JsonResponse
    {
        $service = Service::find($id);

        if (!$service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $data = $request->validated();

        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $service->update($data);

        return response()->json($this->formatService($service->fresh()));
    }

    /**
     * DELETE /api/admin/services/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $service = Service::find($id);

        if (!$service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $service->delete();

        return response()->json(null, 204);
    }

    private function formatService(Service $service): array
    {
        return [
            'id'            => $service->id,
            'name'          => $service->name,
            'slug'          => $service->slug,
            'description'   => $service->description,
            'category'      => $service->category,
            'pricing_model' => $service->pricing_model,
            'base_price'    => $service->base_price,
            'is_active'     => $service->is_active,
            'is_featured'   => $service->is_featured,
            'orders_count'  => $service->orders_count,
            'revenue_total' => $service->revenue_total,
            'dr_tiers'      => [],
            'created_at'    => $service->created_at?->toIso8601String(),
            'updated_at'    => $service->updated_at?->toIso8601String(),
        ];
    }
}
