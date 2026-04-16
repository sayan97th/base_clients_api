<?php

namespace App\Http\Controllers\Client\SmeContent;

use App\Http\Controllers\Controller;
use App\Http\Requests\SmeContent\StoreEnhancedOrderRequest;
use App\Http\Requests\SmeContent\StoreEnhancedPaymentIntentRequest;
use App\Http\Requests\SmeContent\UpdateEnhancedOrderRequest;
use App\Models\SmeEnhancedOrder;
use App\Models\SmeEnhancedTier;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;

class EnhancedContentController extends Controller
{
    public function index(): JsonResponse
    {
        $tiers = SmeEnhancedTier::where('is_active', true)
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
