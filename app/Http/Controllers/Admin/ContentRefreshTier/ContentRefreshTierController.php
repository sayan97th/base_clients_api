<?php

namespace App\Http\Controllers\Admin\ContentRefreshTier;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContentRefreshTier\StoreContentRefreshTierRequest;
use App\Http\Requests\Admin\ContentRefreshTier\UpdateContentRefreshTierRequest;
use App\Models\ContentRefreshTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ContentRefreshTierController extends Controller
{
    /**
     * GET /api/admin/content-refresh-tiers
     */
    public function index(): JsonResponse
    {
        $tiers = ContentRefreshTier::orderBy('sort_order')->get();

        return response()->json($tiers->map(fn (ContentRefreshTier $tier) => $this->formatTier($tier)));
    }

    /**
     * POST /api/admin/content-refresh-tiers
     */
    public function store(StoreContentRefreshTierRequest $request): JsonResponse
    {
        $data       = $request->validated();
        $data['id'] = (string) Str::uuid();

        $tier = ContentRefreshTier::create($data);

        return response()->json($this->formatTier($tier), 201);
    }

    /**
     * PATCH /api/admin/content-refresh-tiers/{id}
     */
    public function update(UpdateContentRefreshTierRequest $request, string $id): JsonResponse
    {
        $tier = ContentRefreshTier::find($id);

        if (!$tier) {
            return response()->json(['message' => "No query results for model [ContentRefreshTier] {$id}"], 404);
        }

        $tier->update($request->validated());

        return response()->json($this->formatTier($tier->fresh()));
    }

    /**
     * DELETE /api/admin/content-refresh-tiers/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $tier = ContentRefreshTier::find($id);

        if (!$tier) {
            return response()->json(['message' => "No query results for model [ContentRefreshTier] {$id}"], 404);
        }

        $tier->delete();

        return response()->json(null, 204);
    }

    private function formatTier(ContentRefreshTier $tier): array
    {
        return [
            'id'               => $tier->id,
            'label'            => $tier->label,
            'word_count_range' => $tier->word_count_range,
            'turnaround_days'  => $tier->turnaround_days,
            'price'            => $tier->price,
            'is_active'        => $tier->is_active,
            'sort_order'       => $tier->sort_order,
            'created_at'       => $tier->created_at?->toIso8601String(),
            'updated_at'       => $tier->updated_at?->toIso8601String(),
        ];
    }
}
