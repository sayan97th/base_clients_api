<?php

namespace App\Http\Controllers\Admin\DrTier;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DrTier\StoreDrTierRequest;
use App\Http\Requests\Admin\DrTier\UpdateDrTierRequest;
use App\Models\DrTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DrTierController extends Controller
{
    /**
     * GET /api/admin/dr-tiers
     */
    public function index(): JsonResponse
    {
        $tiers = DrTier::orderBy('price_per_link')->get();

        return response()->json($tiers->map(fn (DrTier $tier) => $this->formatTier($tier)));
    }

    /**
     * POST /api/admin/dr-tiers
     */
    public function store(StoreDrTierRequest $request): JsonResponse
    {
        $data = $request->validated();

        $tier = DB::transaction(function () use ($data): DrTier {
            if (!empty($data['is_most_popular'])) {
                DrTier::where('is_most_popular', true)->update(['is_most_popular' => false]);
            }

            $data['id'] = (string) Str::uuid();

            return DrTier::create($data);
        });

        return response()->json($this->formatTier($tier), 201);
    }

    /**
     * PATCH /api/admin/dr-tiers/{id}
     */
    public function update(UpdateDrTierRequest $request, string $id): JsonResponse
    {
        $tier = DrTier::find($id);

        if (!$tier) {
            return response()->json(['message' => 'DR Tier not found.'], 404);
        }

        $data = $request->validated();

        DB::transaction(function () use ($tier, $data): void {
            if (isset($data['is_most_popular']) && $data['is_most_popular']) {
                DrTier::where('is_most_popular', true)
                    ->where('id', '!=', $tier->id)
                    ->update(['is_most_popular' => false]);
            }

            $tier->update($data);
        });

        return response()->json($this->formatTier($tier->fresh()));
    }

    /**
     * DELETE /api/admin/dr-tiers/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $tier = DrTier::find($id);

        if (!$tier) {
            return response()->json(['message' => 'DR Tier not found.'], 404);
        }

        $tier->delete();

        return response()->json(null, 204);
    }

    private function formatTier(DrTier $tier): array
    {
        return [
            'id'              => $tier->id,
            'dr_label'        => $tier->dr_label,
            'traffic_range'   => $tier->traffic_range,
            'word_count'      => $tier->word_count,
            'price_per_link'  => $tier->price_per_link,
            'is_most_popular' => $tier->is_most_popular,
            'is_active'       => $tier->is_active,
            'created_at'      => $tier->created_at?->toIso8601String(),
            'updated_at'      => $tier->updated_at?->toIso8601String(),
        ];
    }
}
