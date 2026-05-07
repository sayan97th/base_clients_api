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
            ->get()
            ->map(fn ($tier) => [
                'id'               => $tier->id,
                'label'            => $tier->label,
                'word_count_range' => $tier->word_count_range,
                'turnaround_days'  => $tier->turnaround_days,
                'price'            => (float) $tier->price,
                'is_active'        => $tier->is_active,
                'is_most_popular'  => $tier->is_most_popular,
                'max_quantity'     => $tier->max_quantity,
                'is_hidden'        => $tier->is_hidden,
                'sort_order'       => $tier->sort_order,
                'created_at'       => $tier->created_at,
                'updated_at'       => $tier->updated_at,
            ]);

        return response()->json(['data' => $tiers]);
    }
}
