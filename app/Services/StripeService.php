<?php

namespace App\Services;

use App\Models\User;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class StripeService
{
    private StripeClient $client;

    public function __construct()
    {
        $this->client = new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Retrieve a PaymentMethod from Stripe and return its card details.
     *
     * Returns ['success' => true, 'card' => [...], 'customer_id' => string|null]
     *      or ['success' => false, 'message' => '...']
     */
    public function retrievePaymentMethod(string $payment_method_id): array
    {
        try {
            $payment_method = $this->client->paymentMethods->retrieve($payment_method_id);

            $customer_id = null;
            if ($payment_method->customer !== null) {
                $customer_id = is_string($payment_method->customer)
                    ? $payment_method->customer
                    : $payment_method->customer->id;
            }

            return [
                'success'     => true,
                'customer_id' => $customer_id,
                'card'        => [
                    'brand'     => $payment_method->card->brand,
                    'last4'     => $payment_method->card->last4,
                    'exp_month' => (string) $payment_method->card->exp_month,
                    'exp_year'  => (string) $payment_method->card->exp_year,
                ],
            ];
        } catch (ApiErrorException $e) {
            return [
                'success' => false,
                'message' => 'Invalid payment method: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Attach a PaymentMethod to a Stripe Customer.
     *
     * Returns ['success' => true] or ['success' => false, 'message' => '...']
     */
    public function attachPaymentMethod(string $payment_method_id, string $stripe_customer_id): array
    {
        try {
            $this->client->paymentMethods->attach($payment_method_id, [
                'customer' => $stripe_customer_id,
            ]);

            return ['success' => true];
        } catch (ApiErrorException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Detach a PaymentMethod from its Stripe customer. Failures are silenced.
     */
    public function detachPaymentMethod(string $payment_method_id): void
    {
        try {
            $this->client->paymentMethods->detach($payment_method_id);
        } catch (ApiErrorException) {
            // Silently ignore — PM may already be detached
        }
    }

    /**
     * Verify that a PaymentIntent exists and has status 'succeeded' or 'requires_capture'.
     * When $expected_amount is provided, the PaymentIntent amount (in USD) must match.
     *
     * Returns ['verified' => true, 'intent' => $intent]
     *      or ['verified' => false, 'message' => '...']
     */
    public function verifyPaymentIntent(string $payment_intent_id, ?float $expected_amount = null): array
    {
        try {
            $intent = $this->client->paymentIntents->retrieve($payment_intent_id);

            $valid_statuses = ['succeeded', 'requires_capture'];

            if (!in_array($intent->status, $valid_statuses, strict: true)) {
                return [
                    'verified' => false,
                    'message'  => 'Payment verification failed. The payment was not completed successfully.',
                ];
            }

            if ($expected_amount !== null) {
                $expected_cents = (int) round($expected_amount * 100);
                if ($intent->amount !== $expected_cents) {
                    return [
                        'verified' => false,
                        'message'  => 'Payment amount mismatch. Expected $' . number_format($expected_amount, 2) . ' but the payment was for $' . number_format($intent->amount / 100, 2) . '. Please contact support.',
                    ];
                }
            }

            return ['verified' => true, 'intent' => $intent];
        } catch (ApiErrorException $e) {
            return [
                'verified' => false,
                'message'  => 'Payment verification failed. The payment could not be verified.',
            ];
        }
    }

    /**
     * Refund a PaymentIntent in full. Used as a safety net when a charge succeeds
     * but the subsequent order creation fails — ensures the customer is not billed
     * without receiving an order.
     *
     * Returns ['success' => true, 'refund_id' => '...']
     *      or ['success' => false, 'message' => '...']
     */
    public function refundPaymentIntent(string $payment_intent_id, string $reason = 'other'): array
    {
        try {
            $refund = $this->client->refunds->create([
                'payment_intent' => $payment_intent_id,
                'reason'         => $reason,
            ]);

            return [
                'success'   => true,
                'refund_id' => $refund->id,
            ];
        } catch (ApiErrorException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Map a Stripe error code to a user-friendly message.
     */
    public static function getUserFriendlyErrorMessage(string $error_code, string $fallback = 'Your payment could not be processed. Please try again or use a different card.'): string
    {
        return match ($error_code) {
            'card_declined'              => 'Your card was declined. Please check your card details or contact your bank.',
            'insufficient_funds'         => 'Your card has insufficient funds. Please use a different card or add funds to your account.',
            'incorrect_cvc'              => 'The security code (CVC) is incorrect. Please double-check and try again.',
            'expired_card'               => 'Your card has expired. Please use a different card.',
            'incorrect_number'           => 'Your card number is incorrect. Please check and try again.',
            'invalid_cvc'                => 'The security code (CVC) is invalid. Please check and try again.',
            'invalid_expiry_month'       => 'The expiration month is invalid.',
            'invalid_expiry_year'        => 'The expiration year is invalid.',
            'invalid_number'             => 'Your card number is invalid.',
            'card_velocity_exceeded'     => 'Your card has exceeded its usage limit. Please contact your bank or use a different card.',
            'do_not_honor'               => 'Your card was declined. Please contact your bank for more information.',
            'do_not_try_again'           => 'Your card was declined and should not be retried. Please use a different card.',
            'fraudulent'                 => 'This transaction was flagged as potentially fraudulent. Please contact your bank.',
            'generic_decline'            => 'Your card was declined. Please contact your bank or try a different card.',
            'lost_card'                  => 'Your card has been reported as lost. Please use a different card.',
            'merchant_blacklist'         => 'Your card was declined. Please use a different card.',
            'new_account_information_available' => 'Your card information has changed. Please update your card details.',
            'no_action_taken'            => 'Your card was declined. Please contact your bank.',
            'not_permitted'              => 'This transaction is not permitted on your card. Please contact your bank.',
            'offline_pin_required'       => 'A PIN is required for this card.',
            'online_or_offline_pin_required' => 'A PIN is required for this card.',
            'pickup_card'                => 'Your card has been reported and cannot be used. Please contact your bank.',
            'pin_try_exceeded'           => 'Too many PIN attempts. Please contact your bank.',
            'processing_error'           => 'A processing error occurred. Please try again in a moment.',
            'reenter_transaction'        => 'Please re-enter your card details and try again.',
            'restricted_card'            => 'Your card is restricted. Please contact your bank.',
            'revocation_of_all_authorizations' => 'Your card authorizations have been revoked. Please contact your bank.',
            'security_violation'         => 'A security violation occurred. Please contact your bank.',
            'service_not_allowed'        => 'This service is not allowed on your card. Please use a different card.',
            'stolen_card'                => 'Your card has been reported as stolen. Please use a different card.',
            'stop_payment_order'         => 'A stop payment order is in place on your card. Please contact your bank.',
            'testmode_decline'           => 'Your test card was declined.',
            'transaction_not_allowed'    => 'This transaction is not allowed on your card. Please contact your bank.',
            'try_again_later'            => 'The payment could not be processed. Please try again in a few minutes.',
            'withdrawal_count_limit_exceeded' => 'Your withdrawal limit has been exceeded. Please try again later or use a different card.',
            default                      => $fallback,
        };
    }

    /**
     * Find an existing Stripe Customer for the user or create a new one.
     * Persists the stripe_customer_id back to the user record.
     *
     * Returns the Stripe Customer ID (cus_...) on success.
     * Returns ['success' => false, 'message' => '...'] on failure.
     */
    public function findOrCreateCustomer(User $user): array
    {
        if ($user->stripe_customer_id) {
            return ['success' => true, 'customer_id' => $user->stripe_customer_id];
        }

        try {
            $customer = $this->client->customers->create([
                'email' => $user->email,
                'name'  => $user->full_name,
            ]);

            $user->update(['stripe_customer_id' => $customer->id]);

            return ['success' => true, 'customer_id' => $customer->id];
        } catch (ApiErrorException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Create a Stripe SetupIntent so the client can save a card without charging it.
     *
     * Returns ['success' => true, 'client_secret' => '...']
     *      or ['success' => false, 'message' => '...']
     */
    public function createSetupIntent(string $stripe_customer_id): array
    {
        try {
            $setup_intent = $this->client->setupIntents->create([
                'customer' => $stripe_customer_id,
            ]);

            return [
                'success'       => true,
                'client_secret' => $setup_intent->client_secret,
            ];
        } catch (ApiErrorException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Capture a PaymentIntent that is in requires_capture state.
     * If the intent is already succeeded (automatic capture), this is a no-op.
     *
     * Returns ['success' => true] or ['success' => false, 'message' => '...']
     */
    public function capturePaymentIntent(string $payment_intent_id): array
    {
        try {
            $intent = $this->client->paymentIntents->retrieve($payment_intent_id);

            // Already captured — nothing to do (backward compat with automatic-capture PIs)
            if ($intent->status === 'succeeded') {
                return ['success' => true];
            }

            if ($intent->status !== 'requires_capture') {
                return [
                    'success' => false,
                    'message' => "Cannot capture PaymentIntent in status: {$intent->status}",
                ];
            }

            $this->client->paymentIntents->capture($payment_intent_id);

            return ['success' => true];
        } catch (ApiErrorException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Cancel a PaymentIntent that has been authorized but not yet captured.
     * If the intent is already succeeded (captured), falls back to a full refund
     * for backward compatibility with automatic-capture payment intents.
     *
     * Returns ['success' => true, 'voided' => true]
     *      or ['success' => true, 'voided' => false, 'refund_id' => '...'] (refund path)
     *      or ['success' => false, 'message' => '...']
     */
    public function cancelPaymentIntent(string $payment_intent_id): array
    {
        try {
            $intent = $this->client->paymentIntents->retrieve($payment_intent_id);

            // Already captured — we must refund instead of cancel
            if ($intent->status === 'succeeded') {
                $refund = $this->client->refunds->create(['payment_intent' => $payment_intent_id, 'reason' => 'other']);
                return ['success' => true, 'voided' => false, 'refund_id' => $refund->id];
            }

            $cancelable = ['requires_payment_method', 'requires_capture', 'requires_confirmation', 'requires_action', 'processing'];

            if (! in_array($intent->status, $cancelable, true)) {
                return [
                    'success' => false,
                    'message' => "Cannot cancel PaymentIntent in status: {$intent->status}",
                ];
            }

            $this->client->paymentIntents->cancel($payment_intent_id);

            return ['success' => true, 'voided' => true];
        } catch (ApiErrorException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a Stripe PaymentIntent and return the client_secret and payment_intent_id.
     *
     * Uses capture_method: manual so the card is only authorized (not charged) until
     * capturePaymentIntent() is explicitly called after a successful order creation.
     * This ensures the customer is never charged if order processing fails.
     *
     * If stripe_payment_method_id is provided, it will be attached to the intent (saved card flow).
     * If stripe_customer_id is provided, it will be attached so saved cards can be charged.
     *
     * Returns ['success' => true, 'client_secret' => '...', 'payment_intent_id' => '...']
     *      or ['success' => false, 'message' => '...']
     */
    public function createPaymentIntent(
        int $amount_cents,
        ?string $stripe_payment_method_id = null,
        ?string $stripe_customer_id = null,
        array $metadata = []
    ): array {
        try {
            $params = [
                'amount'         => $amount_cents,
                'currency'       => 'usd',
                'capture_method' => 'manual',
            ];

            if (!empty($metadata)) {
                $params['metadata'] = $metadata;
            }

            if ($stripe_payment_method_id !== null) {
                $params['payment_method'] = $stripe_payment_method_id;
            }

            if ($stripe_customer_id !== null) {
                $params['customer'] = $stripe_customer_id;
            }

            $intent = $this->client->paymentIntents->create($params);

            return [
                'success'           => true,
                'client_secret'     => $intent->client_secret,
                'payment_intent_id' => $intent->id,
            ];
        } catch (ApiErrorException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
