<?php

namespace App\Http\Controllers\Client\SmeContent;

use App\Http\Controllers\Controller;
use App\Models\SmeAuthoredTier;
use Illuminate\Http\JsonResponse;

class AuthoredContentController extends Controller
{
    private const FEATURES = [
        'Comprehensive content creation from research to final delivery',
        'Editorial oversight ensuring quality and brand alignment',
        'Content that demonstrates genuine expertise Google rewards',
    ];

    private const CONTENT_TYPES = [
        'Home Page',
        'About Us Page',
        'Blog Article',
        'Product page',
    ];

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'tiers'         => $this->getTiers(),
                'features'      => self::FEATURES,
                'content_types' => self::CONTENT_TYPES,
            ],
        ]);
    }

    public function tiers(): JsonResponse
    {
        return response()->json(['data' => $this->getTiers()]);
    }

    public function features(): JsonResponse
    {
        return response()->json(['data' => self::FEATURES]);
    }

    public function contentTypes(): JsonResponse
    {
        return response()->json(['data' => self::CONTENT_TYPES]);
    }

    private function getTiers(): array
    {
        return SmeAuthoredTier::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($tier) => [
                'id'          => $tier->tier_key,
                'label'       => $tier->label,
                'description' => $tier->description,
                'price'       => $tier->price,
            ])
            ->values()
            ->all();
    }
}
