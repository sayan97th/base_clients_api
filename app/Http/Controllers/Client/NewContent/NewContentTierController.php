<?php

namespace App\Http\Controllers\Client\NewContent;

use App\Http\Controllers\Controller;
use App\Models\NewContentTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewContentTierController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = NewContentTier::where('is_active', true)
            ->orderBy('price_per_month', 'asc')
            ->get();

        $data = $plans->map(fn ($plan) => [
            'id'                    => $plan->id,
            'name'                  => $plan->name,
            'price_per_month'       => $plan->price_per_month,
            'total_placements'      => $plan->total_placements,
            'exclusive_placements'  => $plan->exclusive_placements,
            'core_placements'       => $plan->core_placements,
            'support_placements'    => $plan->support_placements,
            'best_for'              => $plan->best_for,
            'tagline'               => $plan->tagline,
            'is_most_popular'       => $plan->is_most_popular,
            'is_active'             => $plan->is_active,
        ]);

        return response()->json(['data' => $data]);
    }
}
