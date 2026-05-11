<?php

namespace App\Http\Controllers\Client\Stripe;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripeController extends Controller
{
    public function __construct(protected StripeService $stripeService) {}

    /**
     * POST /api/stripe/create-payment-intent
     *
     * Creates a Stripe PaymentIntent and returns the client_secret to the frontend
     * so that Stripe Elements can confirm the payment on the client side.
     */
    public function createPaymentIntent(Request $request): JsonResponse
    {
        $request->validate([
            'amount_cents'              => ['required', 'integer', 'min:50'],
            'stripe_payment_method_id'  => ['nullable', 'string'],
            'metadata'                  => ['nullable', 'array'],
        ]);

        /** @var User $user */
        $user                      = auth()->user();
        $stripe_payment_method_id  = $request->stripe_payment_method_id;
        $stripe_customer_id        = null;

        // When charging a saved card, attach the user's Stripe Customer so
        // the payment method can be reused on the intent.
        if ($stripe_payment_method_id !== null) {
            $customer_result = $this->stripeService->findOrCreateCustomer($user);

            if (!$customer_result['success']) {
                return response()->json(['error' => 'Failed to create payment intent.'], 422);
            }

            $stripe_customer_id = $customer_result['customer_id'];
        }

        $result = $this->stripeService->createPaymentIntent(
            amount_cents: $request->amount_cents,
            stripe_payment_method_id: $stripe_payment_method_id,
            stripe_customer_id: $stripe_customer_id,
            metadata: $request->metadata ?? [],
        );

        if (!$result['success']) {
            return response()->json(['error' => 'Failed to create payment intent.'], 422);
        }

        return response()->json([
            'client_secret'     => $result['client_secret'],
            'payment_intent_id' => $result['payment_intent_id'],
        ]);
    }
}
