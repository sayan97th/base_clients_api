<?php

namespace App\Http\Controllers\Client\SeoPackages;

use App\Http\Controllers\Controller;
use App\Http\Requests\SeoPackages\StoreSeoSubscriptionRequest;
use App\Models\SeoPackage;
use App\Models\SeoSubscription;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SeoSubscriptionController extends Controller
{
    public function __construct(
        protected StripeService $stripeService
    ) {}

    public function store(StoreSeoSubscriptionRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $package = SeoPackage::where('id', $request->package_id)
            ->where('is_active', true)
            ->first();

        if (!$package) {
            return response()->json(['message' => 'The requested package is not available.'], 404);
        }

        $package_price = round((float) $package->price_per_month, 2);

        if (abs($package_price - (float) $request->total_amount) > 0.01) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => ['total_amount' => ['The total amount does not match the package price.']],
            ], 422);
        }

        $payment_method_id = $request->payment['payment_method_id'];
        $stripe_result     = $this->stripeService->verifyPaymentIntent($payment_method_id);

        if (!$stripe_result['verified']) {
            return response()->json([
                'message' => 'Your card was declined. Please try a different payment method.',
            ], 402);
        }

        $subscription = DB::transaction(function () use ($request, $user, $package, $package_price, $payment_method_id) {
            return SeoSubscription::create([
                'user_id'             => $user->id,
                'package_id'          => $package->id,
                'status'              => 'active',
                'total_amount'        => $package_price,
                'payment_method_id'   => $payment_method_id,
                'billing_company'     => !empty($request->billing['company']) ? $request->billing['company'] : null,
                'billing_address'     => !empty($request->billing['address']) ? $request->billing['address'] : null,
                'billing_city'        => !empty($request->billing['city']) ? $request->billing['city'] : null,
                'billing_state'       => !empty($request->billing['state']) ? $request->billing['state'] : null,
                'billing_country'     => !empty($request->billing['country']) ? $request->billing['country'] : null,
                'billing_postal_code' => !empty($request->billing['postal_code']) ? $request->billing['postal_code'] : null,
            ]);
        });

        return response()->json([
            'data' => [
                'subscription_id' => $subscription->id,
                'package_id'      => $subscription->package_id,
                'status'          => $subscription->status,
                'total_amount'    => $subscription->total_amount,
                'created_at'      => $subscription->created_at,
            ],
        ], 201);
    }
}
