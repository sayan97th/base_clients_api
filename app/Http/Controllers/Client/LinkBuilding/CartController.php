<?php

namespace App\Http\Controllers\Client\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Http\Requests\LinkBuilding\UpsertCartRequest;
use App\Models\LinkBuildingCart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CartController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $cart = LinkBuildingCart::where('user_id', $request->user()->id)->first();

        return response()->json(['data' => $cart?->payload]);
    }

    public function upsert(UpsertCartRequest $request): Response
    {
        LinkBuildingCart::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['payload'  => $request->validated()]
        );

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        LinkBuildingCart::where('user_id', $request->user()->id)->delete();

        return response()->noContent();
    }
}
