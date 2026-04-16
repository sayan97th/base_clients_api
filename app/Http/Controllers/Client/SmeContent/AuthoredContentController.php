<?php

namespace App\Http\Controllers\Client\SmeContent;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AuthoredContentController extends Controller
{
    private const TIERS = [
        [
            'id'          => 'sme_authored_1000_1499',
            'label'       => 'SME Authored Content - 1,000-1,499 Words',
            'description' => 'Industry experts with verified credentials create comprehensive content from research to final delivery with editorial oversight.',
            'price'       => 2000,
        ],
        [
            'id'          => 'sme_authored_1500_1999',
            'label'       => 'SME Authored Content - 1,500-1,999 Words',
            'description' => 'Industry experts with verified credentials create comprehensive content from research to final delivery with editorial oversight.',
            'price'       => 3000,
        ],
        [
            'id'          => 'sme_authored_2000_plus',
            'label'       => 'SME Authored Content - 2,000+ Words',
            'description' => 'Industry experts with verified credentials create comprehensive content from research to final delivery with editorial oversight.',
            'price'       => 4000,
        ],
    ];

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
                'tiers'         => self::TIERS,
                'features'      => self::FEATURES,
                'content_types' => self::CONTENT_TYPES,
            ],
        ]);
    }

    public function tiers(): JsonResponse
    {
        return response()->json(['data' => self::TIERS]);
    }

    public function features(): JsonResponse
    {
        return response()->json(['data' => self::FEATURES]);
    }

    public function contentTypes(): JsonResponse
    {
        return response()->json(['data' => self::CONTENT_TYPES]);
    }
}
