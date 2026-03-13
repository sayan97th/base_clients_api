<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StorePaymentMethodRequest;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Stripe\Exception\ApiErrorException;

class PaymentMethodController extends Controller
{
    public function __construct(
        protected StripeService $stripeService
    ) {}

    /**
     * GET /billing/payment-methods
     *
     * List all saved payment profiles for the authenticated user.
     */
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $payment_methods = $user->paymentMethods()
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($pm) => $this->formatPaymentMethod($pm));

        return response()->json(['data' => $payment_methods]);
    }

    /**
     * POST /billing/payment-methods
     *
     * Attach a Stripe PaymentMethod (pm_xxx) to the user's billing profile.
     *
     * The frontend should obtain the stripe_payment_method_id via:
     *   1. stripe.createPaymentMethod({ type: 'card', card }) — for immediate attach
     *   2. After confirming a SetupIntent (POST /billing/setup-intent flow)
     *
     * Body:
     *   stripe_payment_method_id  string  required
     *   set_as_default            bool    optional (defaults to true when first card)
     */
    public function store(StorePaymentMethodRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        // Prevent duplicate payment method entries
        $existing = PaymentMethod::where('user_id', $user->id)
            ->where('stripe_payment_method_id', $request->stripe_payment_method_id)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'This payment method is already saved to your account.',
                'data'    => $this->formatPaymentMethod($existing),
            ], 409);
        }

        try {
            $payment_method = $this->stripeService->attachPaymentMethod(
                $user,
                $request->stripe_payment_method_id,
                $request->boolean('set_as_default', true)
            );

            return response()->json([
                'message' => 'Payment method saved successfully.',
                'data'    => $this->formatPaymentMethod($payment_method),
            ], 201);
        } catch (ApiErrorException $e) {
            return response()->json([
                'message' => 'Could not attach payment method: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * PUT /billing/payment-methods/{payment_method}/default
     *
     * Mark a saved payment method as the default for future payments.
     */
    public function setDefault(PaymentMethod $payment_method): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        if ($payment_method->user_id !== $user->id) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        if ($payment_method->is_default) {
            return response()->json([
                'message' => 'This payment method is already your default.',
                'data'    => $this->formatPaymentMethod($payment_method),
            ]);
        }

        try {
            $this->stripeService->setDefaultPaymentMethod($user, $payment_method);
            $payment_method->refresh();

            return response()->json([
                'message' => 'Default payment method updated.',
                'data'    => $this->formatPaymentMethod($payment_method),
            ]);
        } catch (ApiErrorException $e) {
            return response()->json([
                'message' => 'Could not update default payment method: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * DELETE /billing/payment-methods/{payment_method}
     *
     * Remove a saved payment method from the user's billing profile.
     */
    public function destroy(PaymentMethod $payment_method): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        if ($payment_method->user_id !== $user->id) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        try {
            $this->stripeService->detachPaymentMethod($user, $payment_method);

            return response()->json(['message' => 'Payment method removed.']);
        } catch (ApiErrorException $e) {
            return response()->json([
                'message' => 'Could not remove payment method: ' . $e->getMessage(),
            ], 422);
        }
    }

    private function formatPaymentMethod(PaymentMethod $pm): array
    {
        return [
            'id'               => $pm->id,
            'card_brand'       => $pm->card_brand,
            'card_last_four'   => $pm->card_last_four,
            'card_exp_month'   => $pm->card_exp_month,
            'card_exp_year'    => $pm->card_exp_year,
            'cardholder_name'  => $pm->cardholder_name,
            'billing_zip'      => $pm->billing_zip,
            'is_default'       => $pm->is_default,
            'is_expired'       => $pm->isExpired(),
            'card_summary'     => $pm->card_summary,
            'expiry'           => $pm->expiry,
            'created_at'       => $pm->created_at,
        ];
    }
}
