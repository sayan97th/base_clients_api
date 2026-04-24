<?php

namespace App\Http\Controllers\Admin\ContentOptimization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContentOptimization\StoreContentOptimizationTierRequest;
use App\Http\Requests\Admin\ContentOptimization\UpdateContentOptimizationTierRequest;
use App\Models\ContentOptimizationTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminContentOptimizationTierController extends Controller
{
    public function index(): JsonResponse
    {
        $tiers = ContentOptimizationTier::orderBy('sort_order')->get();

        return response()->json($tiers->map(fn (ContentOptimizationTier $tier) => $this->formatTier($tier)));
    }

    public function show(string $id): JsonResponse
    {
        $tier = ContentOptimizationTier::find($id);

        if (!$tier) {
            return response()->json(['message' => 'Content optimization tier not found.'], 404);
        }

        return response()->json($this->formatTier($tier));
    }

    public function store(StoreContentOptimizationTierRequest $request): JsonResponse
    {
        $data = $request->validated();

        $tier = DB::transaction(function () use ($data): ContentOptimizationTier {
            if (!empty($data['is_most_popular'])) {
                ContentOptimizationTier::where('is_most_popular', true)->update(['is_most_popular' => false]);
            }

            return ContentOptimizationTier::create($data);
        });

        return response()->json($this->formatTier($tier), 201);
    }

    public function update(UpdateContentOptimizationTierRequest $request, string $id): JsonResponse
    {
        $tier = ContentOptimizationTier::find($id);

        if (!$tier) {
            return response()->json(['message' => 'Content optimization tier not found.'], 404);
        }

        $data = $request->validated();

        // Strip id if the client accidentally sends it — the id is immutable after creation.
        unset($data['id']);

        DB::transaction(function () use ($tier, $data): void {
            if (isset($data['is_most_popular']) && $data['is_most_popular']) {
                ContentOptimizationTier::where('is_most_popular', true)
                    ->where('id', '!=', $tier->id)
                    ->update(['is_most_popular' => false]);
            }

            $tier->update($data);
        });

        $tier->refresh();

        return response()->json($this->formatTier($tier));
    }

    public function destroy(string $id): JsonResponse
    {
        $tier = ContentOptimizationTier::find($id);

        if (!$tier) {
            return response()->json(['message' => 'Content optimization tier not found.'], 404);
        }

        if ($tier->orderItems()->exists()) {
            return response()->json([
                'message' => 'This tier cannot be deleted because it is associated with existing orders.',
            ], 409);
        }

        $tier->delete();

        return response()->json(null, 204);
    }

    private function formatTier(ContentOptimizationTier $tier): array
    {
        return [
            'id'               => $tier->id,
            'label'            => $tier->label,
            'word_count_range' => $tier->word_count_range,
            'turnaround_days'  => $tier->turnaround_days,
            'price'            => $tier->price,
            'is_active'        => $tier->is_active,
            'is_most_popular'  => $tier->is_most_popular,
            'max_quantity'     => $tier->max_quantity,
            'is_hidden'        => $tier->is_hidden,
            'sort_order'       => $tier->sort_order,
            'created_at'       => $tier->created_at?->toIso8601String(),
            'updated_at'       => $tier->updated_at?->toIso8601String(),
        ];
    }
}
