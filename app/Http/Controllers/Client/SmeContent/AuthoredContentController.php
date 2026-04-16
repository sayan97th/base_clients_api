<?php

namespace App\Http\Controllers\Client\SmeContent;

use App\Http\Controllers\Controller;
use App\Models\SmeAuthoredTier;
use Illuminate\Http\JsonResponse;

class AuthoredContentController extends Controller
{
    public function index(): JsonResponse
    {
        $tiers = SmeAuthoredTier::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn($tier) => [
                'id'          => $tier->tier_key,
                'label'       => $tier->label,
                'description' => $tier->description,
                'price'       => $tier->price,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $tiers]);
    }
}
