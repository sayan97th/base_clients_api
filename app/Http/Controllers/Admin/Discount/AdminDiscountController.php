<?php

namespace App\Http\Controllers\Admin\Discount;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Discount\StoreDiscountRequest;
use App\Http\Requests\Admin\Discount\UpdateDiscountRequest;
use App\Models\Discount;
use Illuminate\Http\JsonResponse;

class AdminDiscountController extends Controller
{
    public function index(): JsonResponse
    {
        $discounts = Discount::with('drTiers')->orderBy('created_at', 'desc')->get();

        return response()->json(['data' => $discounts->map(fn ($d) => $this->formatDiscount($d))]);
    }

    public function store(StoreDiscountRequest $request): JsonResponse
    {
        $validated  = $request->validated();
        $dr_tier_ids = $validated['dr_tier_ids'] ?? [];
        unset($validated['dr_tier_ids']);

        $discount = Discount::create($validated);

        if (! empty($dr_tier_ids)) {
            $discount->drTiers()->sync($dr_tier_ids);
        }

        $discount->load('drTiers');

        return response()->json(['data' => $this->formatDiscount($discount)], 201);
    }

    public function show(string $id): JsonResponse
    {
        $discount = Discount::with('drTiers')->find($id);

        if (! $discount) {
            return response()->json(['message' => 'Discount not found.'], 404);
        }

        return response()->json(['data' => $this->formatDiscount($discount)]);
    }

    public function update(UpdateDiscountRequest $request, string $id): JsonResponse
    {
        $discount = Discount::find($id);

        if (! $discount) {
            return response()->json(['message' => 'Discount not found.'], 404);
        }

        $validated   = $request->validated();
        $dr_tier_ids = $validated['dr_tier_ids'] ?? null;
        unset($validated['dr_tier_ids']);

        $discount->update($validated);

        if ($dr_tier_ids !== null) {
            $discount->drTiers()->sync($dr_tier_ids);
        }

        $discount->load('drTiers');

        return response()->json(['data' => $this->formatDiscount($discount->fresh())]);
    }

    public function destroy(string $id): JsonResponse
    {
        $discount = Discount::find($id);

        if (! $discount) {
            return response()->json(['message' => 'Discount not found.'], 404);
        }

        $discount->delete();

        return response()->json(['message' => 'Discount deleted successfully.']);
    }

    private function formatDiscount(Discount $discount): array
    {
        $data              = $discount->toArray();
        $data['dr_tiers']  = $discount->drTiers->values()->toArray();
        $data['dr_tier_ids'] = $discount->drTiers->pluck('id')->values()->toArray();

        return $data;
    }
}
