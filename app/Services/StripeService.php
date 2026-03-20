<?php

namespace App\Services;

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
     * Returns ['success' => true, 'card' => [...]] or ['success' => false, 'message' => '...']
     */
    public function retrievePaymentMethod(string $payment_method_id): array
    {
        try {
            $payment_method = $this->client->paymentMethods->retrieve($payment_method_id);

            return [
                'success' => true,
                'card'    => [
                    'brand'     => $payment_method->card->brand,
                    'last4'     => $payment_method->card->last4,
                    'exp_month' => str_pad((string) $payment_method->card->exp_month, 2, '0', STR_PAD_LEFT),
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
     *
     * Returns ['verified' => true] or ['verified' => false, 'message' => '...']
     */
    public function verifyPaymentIntent(string $payment_intent_id): array
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

            return ['verified' => true];
        } catch (ApiErrorException $e) {
            return [
                'verified' => false,
                'message'  => 'Payment verification failed. The payment could not be verified.',
            ];
        }
    }

    /**
     * Create a Stripe PaymentIntent and return the client_secret and payment_intent_id.
     *
     * If stripe_payment_method_id is provided, it will be attached to the intent (saved card flow).
     *
     * Returns ['success' => true, 'client_secret' => '...', 'payment_intent_id' => '...']
     *      or ['success' => false, 'message' => '...']
     */
    public function createPaymentIntent(int $amount_cents, ?string $stripe_payment_method_id = null, array $metadata = []): array
    {
        try {
            $params = [
                'amount'   => $amount_cents,
                'currency' => 'usd',
            ];

            if (!empty($metadata)) {
                $params['metadata'] = $metadata;
            }

            if ($stripe_payment_method_id !== null) {
                $params['payment_method'] = $stripe_payment_method_id;
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
