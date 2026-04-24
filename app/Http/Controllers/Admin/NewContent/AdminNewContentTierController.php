<?php

namespace App\Http\Controllers\Admin\NewContent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NewContent\StoreNewContentTierRequest;
use App\Http\Requests\Admin\NewContent\UpdateNewContentTierRequest;
use App\Models\NewContentTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminNewContentTierController extends Controller
{
    public function index(): JsonResponse
    {
        $tiers = NewContentTier::orderBy('sort_order')->get();

        return response()->json($tiers->map(fn (NewContentTier $tier) => $this->formatTier($tier)));
    }

    public function show(string $id): JsonResponse
    {
        $tier = NewContentTier::find($id);

        if (!$tier) {
            return response()->json(['message' => 'New content tier not found.'], 404);
        }

        return response()->json($this->formatTier($tier));
    }

    public function store(StoreNewContentTierRequest $request): JsonResponse
    {
        $data = $request->validated();

        $tier = DB::transaction(function () use ($data): NewContentTier {
            if (!empty($data['is_most_popular'])) {
                NewContentTier::where('is_most_popular', true)->update(['is_most_popular' => false]);
            }

            return NewContentTier::create($data);
        });

        return response()->json($this->formatTier($tier), 201);
    }

    public function update(UpdateNewContentTierRequest $request, string $id): JsonResponse
    {
        $tier = NewContentTier::find($id);

        if (!$tier) {
            return response()->json(['message' => 'New content tier not found.'], 404);
        }

        $data = $request->validated();

        DB::transaction(function () use ($tier, $data): void {
            if (isset($data['is_most_popular']) && $data['is_most_popular']) {
                NewContentTier::where('is_most_popular', true)
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
        $tier = NewContentTier::find($id);

        if (!$tier) {
            return response()->json(['message' => 'New content tier not found.'], 404);
        }

        $tier->delete();

        return response()->json(null, 204);
    }

    private function formatTier(NewContentTier $tier): array
    {
        return [
            'id'              => $tier->id,
            'label'           => $tier->label,
            'turnaround_time' => $tier->turnaround_time,
            'price'           => $tier->price,
            'is_active'       => $tier->is_active,
            'is_most_popular' => $tier->is_most_popular,
            'max_quantity'    => $tier->max_quantity,
            'is_hidden'       => $tier->is_hidden,
            'sort_order'      => $tier->sort_order,
            'created_at'      => $tier->created_at?->toIso8601String(),
            'updated_at'      => $tier->updated_at?->toIso8601String(),
        ];
    }
}
