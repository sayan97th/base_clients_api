<?php

namespace App\Http\Controllers\Client\SeoPackages;

use App\Http\Controllers\Controller;
use App\Http\Resources\SeoPackageSubscriptionResource;
use App\Models\SeoPackageSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeoPackageSubscriptionController extends Controller
{
    public function active(Request $request): JsonResponse
    {
        $subscription = SeoPackageSubscription::with('package:id,name,slug,price_per_month')
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('ends_at')
                  ->orWhere('ends_at', '>=', now()->toDateString());
            })
            ->latest('created_at')
            ->first();

        return response()->json([
            'data' => $subscription ? new SeoPackageSubscriptionResource($subscription) : null,
        ]);
    }
}
