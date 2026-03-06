<?php

namespace App\Http\Controllers\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Models\DrTier;
use Illuminate\Http\JsonResponse;

class DrTierController extends Controller
{
    public function index(): JsonResponse
    {
        $tiers = DrTier::where('is_active', true)
            ->orderBy('price_per_link')
            ->get();

        return response()->json(['data' => $tiers]);
    }
}
