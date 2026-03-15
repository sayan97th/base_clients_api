<?php

namespace App\Http\Controllers\Client\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Models\DrTier;
use Illuminate\Http\JsonResponse;

class DrTierController extends Controller
{
    public function index(): JsonResponse
    {
        $tiers = DrTier::where('is_active', true)
            ->where('is_hidden', false)
            ->orderBy('price_per_link')
            ->get();

        return response()->json(['data' => $tiers]);
    }
}
