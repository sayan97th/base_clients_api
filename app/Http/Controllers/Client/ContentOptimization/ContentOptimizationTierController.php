<?php

namespace App\Http\Controllers\Client\ContentOptimization;

use App\Http\Controllers\Controller;
use App\Models\ContentOptimizationTier;
use Illuminate\Http\JsonResponse;

class ContentOptimizationTierController extends Controller
{
    public function index(): JsonResponse
    {
        $tiers = ContentOptimizationTier::where('is_active', true)
            ->where('is_hidden', false)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $tiers]);
    }
}
