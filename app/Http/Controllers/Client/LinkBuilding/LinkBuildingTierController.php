<?php

namespace App\Http\Controllers\Client\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Models\DrTier;
use Illuminate\Http\JsonResponse;

class LinkBuildingTierController extends Controller
{
    public function index(): JsonResponse
    {
        $tiers = DrTier::where('is_active', true)
            ->where('is_hidden', false)
            ->orderBy('price_per_link')
            ->get()
            ->map(fn($tier) => [
                'id'              => $tier->id,
                'label'           => $tier->label,
                'traffic_range'   => $tier->traffic_range,
                'word_count'      => $tier->word_count,
                'price_per_link'  => $tier->price_per_link,
                'is_most_popular' => $tier->is_most_popular,
                'is_active'       => $tier->is_active,
                'max_quantity'    => $tier->max_quantity,
            ]);

        return response()->json(['data' => $tiers]);
    }
}
