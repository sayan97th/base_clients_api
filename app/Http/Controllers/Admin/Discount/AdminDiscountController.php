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
        $discounts = Discount::orderBy('created_at', 'desc')->get();

        return response()->json(['data' => $discounts]);
    }

    public function store(StoreDiscountRequest $request): JsonResponse
    {
        $discount = Discount::create($request->validated());

        return response()->json(['data' => $discount], 201);
    }

    public function show(string $id): JsonResponse
    {
        $discount = Discount::findOrFail($id);

        return response()->json(['data' => $discount]);
    }

    public function update(UpdateDiscountRequest $request, string $id): JsonResponse
    {
        $discount = Discount::findOrFail($id);
        $discount->update($request->validated());

        return response()->json(['data' => $discount->fresh()]);
    }

    public function destroy(string $id): JsonResponse
    {
        $discount = Discount::findOrFail($id);
        $discount->delete();

        return response()->json(null, 204);
    }
}
