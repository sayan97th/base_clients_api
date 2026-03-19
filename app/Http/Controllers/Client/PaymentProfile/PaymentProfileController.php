<?php

namespace App\Http\Controllers\Client\PaymentProfile;

use App\Http\Controllers\Controller;
use App\Models\PaymentProfile;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentProfileController extends Controller
{
    public function __construct(protected StripeService $stripeService) {}

    public function index(): JsonResponse
    {
        $profiles = PaymentProfile::where('user_id', auth()->id())
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($profile) => $this->formatProfile($profile));

        return response()->json(['data' => $profiles]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'stripe_payment_method_id' => ['required', 'string'],
            'cardholder_name'          => ['nullable', 'string', 'max:255'],
            'is_default'               => ['nullable', 'boolean'],
        ]);

        $stripe_result = $this->stripeService->retrievePaymentMethod($request->stripe_payment_method_id);

        if (!$stripe_result['success']) {
            return response()->json(['message' => $stripe_result['message']], 422);
        }

        $card       = $stripe_result['card'];
        $user_id    = auth()->id();
        $has_cards  = PaymentProfile::where('user_id', $user_id)->exists();
        $is_default = $has_cards ? (bool) ($request->is_default ?? false) : true;

        $profile = DB::transaction(function () use ($user_id, $request, $card, $is_default) {
            if ($is_default) {
                PaymentProfile::where('user_id', $user_id)->update(['is_default' => false]);
            }

            return PaymentProfile::create([
                'user_id'                  => $user_id,
                'stripe_payment_method_id' => $request->stripe_payment_method_id,
                'card_brand'               => $card['brand'],
                'last_four'                => $card['last4'],
                'expiry_month'             => $card['exp_month'],
                'expiry_year'              => $card['exp_year'],
                'cardholder_name'          => $request->cardholder_name,
                'is_default'               => $is_default,
            ]);
        });

        return response()->json([
            'data'    => $this->formatProfile($profile),
            'message' => 'Payment method saved successfully.',
        ], 201);
    }

    public function destroy(string $id): JsonResponse
    {
        $user_id = auth()->id();

        $profile = PaymentProfile::where('id', $id)
            ->where('user_id', $user_id)
            ->first();

        if (!$profile) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        $this->stripeService->detachPaymentMethod($profile->stripe_payment_method_id);

        $was_default = $profile->is_default;
        $profile->delete();

        if ($was_default) {
            PaymentProfile::where('user_id', $user_id)
                ->orderByDesc('created_at')
                ->first()
                ?->update(['is_default' => true]);
        }

        return response()->json(['message' => 'Payment method removed successfully.']);
    }

    public function setDefault(string $id, Request $request): JsonResponse
    {
        $request->validate([
            'is_default' => ['required', 'boolean', 'accepted'],
        ]);

        $user_id = auth()->id();

        $profile = PaymentProfile::where('id', $id)
            ->where('user_id', $user_id)
            ->first();

        if (!$profile) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        DB::transaction(function () use ($user_id, $profile) {
            PaymentProfile::where('user_id', $user_id)->update(['is_default' => false]);
            $profile->update(['is_default' => true]);
        });

        return response()->json([
            'data'    => $this->formatProfile($profile->fresh()),
            'message' => 'Default payment method updated.',
        ]);
    }

    private function formatProfile(PaymentProfile $profile): array
    {
        return [
            'id'                       => $profile->id,
            'stripe_payment_method_id' => $profile->stripe_payment_method_id,
            'card_brand'               => $profile->card_brand,
            'last_four'                => $profile->last_four,
            'expiry_month'             => $profile->expiry_month,
            'expiry_year'              => $profile->expiry_year,
            'cardholder_name'          => $profile->cardholder_name,
            'is_default'               => $profile->is_default,
            'created_at'               => $profile->created_at,
            'updated_at'               => $profile->updated_at,
        ];
    }
}
