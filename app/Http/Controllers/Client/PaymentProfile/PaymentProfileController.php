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
            'stripe_payment_method_id'        => ['required', 'string', 'starts_with:pm_'],
            'cardholder_name'                 => ['nullable', 'string', 'max:255'],
            'is_default'                      => ['required', 'boolean'],
            'billing_address'                 => ['nullable', 'array'],
            'billing_address.address_line1'   => ['nullable', 'string', 'max:255'],
            'billing_address.city'            => ['nullable', 'string', 'max:255'],
            'billing_address.state'           => ['nullable', 'string', 'max:255'],
            'billing_address.postal_code'     => ['nullable', 'string', 'max:20'],
            'billing_address.country'         => ['nullable', 'string', 'size:2'],
            'billing_address.company'         => ['nullable', 'string', 'max:255'],
        ]);

        $already_saved = PaymentProfile::where('user_id', auth()->id())
            ->where('stripe_payment_method_id', $request->stripe_payment_method_id)
            ->exists();

        if ($already_saved) {
            return response()->json([
                'message' => 'This payment method is already saved to your account.',
            ], 409);
        }

        $stripe_result = $this->stripeService->retrievePaymentMethod($request->stripe_payment_method_id);

        if (!$stripe_result['success']) {
            return response()->json(['message' => 'Failed to retrieve card details from Stripe.'], 500);
        }

        /** @var \App\Models\User $user */
        $user            = auth()->user();
        $customer_result = $this->stripeService->findOrCreateCustomer($user);

        if (!$customer_result['success']) {
            return response()->json(['message' => 'Failed to link payment method to your account.'], 500);
        }

        $pm_customer_id = $stripe_result['customer_id'] ?? null;

        // When the PaymentIntent was created with setup_future_usage (save-at-checkout
        // flow), Stripe automatically attaches the PM to the Customer during confirmation.
        // If the PM is already on the correct customer we skip the redundant attach call.
        if ($pm_customer_id !== $customer_result['customer_id']) {
            if ($pm_customer_id !== null) {
                // PM is attached to a different Stripe Customer — cannot save.
                return response()->json([
                    'message' => 'This payment method is already associated with a different account.',
                ], 409);
            }

            $attach_result = $this->stripeService->attachPaymentMethod(
                $request->stripe_payment_method_id,
                $customer_result['customer_id']
            );

            if (!$attach_result['success']) {
                return response()->json(['message' => 'Failed to attach payment method to your account.'], 500);
            }
        }

        $card       = $stripe_result['card'];
        $user_id    = $user->id;
        $has_cards  = PaymentProfile::where('user_id', $user_id)->exists();
        $is_default = $has_cards ? (bool) $request->is_default : true;
        $billing    = $request->billing_address ?? [];

        $profile = DB::transaction(function () use ($user_id, $request, $card, $is_default, $billing) {
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
                'billing_address_line1'    => $billing['address_line1'] ?? null,
                'billing_address_city'     => $billing['city'] ?? null,
                'billing_address_state'    => $billing['state'] ?? null,
                'billing_address_postal'   => $billing['postal_code'] ?? null,
                'billing_address_country'  => $billing['country'] ?? null,
                'billing_address_company'  => $billing['company'] ?? null,
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

        $profile = PaymentProfile::find($id);

        if (!$profile) {
            return response()->json(['message' => 'Payment profile not found.'], 404);
        }

        if ($profile->user_id !== $user_id) {
            return response()->json(['message' => 'Payment profile not found.'], 404);
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

        $profile = PaymentProfile::find($id);

        if (!$profile) {
            return response()->json(['message' => 'Payment profile not found.'], 404);
        }

        if ($profile->user_id !== $user_id) {
            return response()->json(['message' => 'Payment profile not found.'], 404);
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
        $has_billing_address =
            $profile->billing_address_line1 !== null ||
            $profile->billing_address_city !== null ||
            $profile->billing_address_state !== null ||
            $profile->billing_address_postal !== null ||
            $profile->billing_address_country !== null ||
            $profile->billing_address_company !== null;

        return [
            'id'                       => $profile->id,
            'stripe_payment_method_id' => $profile->stripe_payment_method_id,
            'card_brand'               => $profile->card_brand,
            'last_four'                => $profile->last_four,
            'expiry_month'             => $profile->expiry_month,
            'expiry_year'              => $profile->expiry_year,
            'cardholder_name'          => $profile->cardholder_name,
            'billing_address'          => $has_billing_address ? [
                'address_line1' => $profile->billing_address_line1,
                'city'          => $profile->billing_address_city,
                'state'         => $profile->billing_address_state,
                'postal_code'   => $profile->billing_address_postal,
                'country'       => $profile->billing_address_country,
                'company'       => $profile->billing_address_company,
            ] : null,
            'is_default'               => $profile->is_default,
            'created_at'               => $profile->created_at,
            'updated_at'               => $profile->updated_at,
        ];
    }
}
