<?php

namespace App\Http\Controllers\Client\SmeContent;

use App\Http\Controllers\Controller;
use App\Http\Requests\SmeContent\StoreCollaborationOrderRequest;
use App\Http\Requests\SmeContent\StoreCollaborationPaymentIntentRequest;
use App\Http\Requests\SmeContent\UpdateCollaborationOrderRequest;
use App\Models\SmeCollaborationOrder;
use App\Models\SmeCollaborationTier;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;

class InternalCollaborationController extends Controller
{
    public function index(): JsonResponse
    {
        $tiers = SmeCollaborationTier::where('is_active', true)
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
