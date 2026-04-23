<?php

namespace App\Http\Controllers\Client\ContentRefresh;

use App\Http\Controllers\Controller;
use App\Models\ContentRefreshTier;
use Illuminate\Http\JsonResponse;

class ContentRefreshTierController extends Controller
{
    /**
     * GET /api/content-refresh-tiers
     */
    public function index(): JsonResponse
    {
        $tiers = ContentRefreshTier::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn(ContentRefreshTier $tier) => [
                'id'               => $tier->id,
                'label'            => $tier->label,
                'word_count_range' => $tier->word_count_range,
                'turnaround_days'  => $tier->turnaround_days,
                'price'            => $tier->price,
                'sort_order'       => $tier->sort_order,
            ]);

        return response()->json(['data' => $tiers]);
    }
}
