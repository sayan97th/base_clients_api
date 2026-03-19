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
     * Verify that a PaymentIntent exists and has status 'succeeded'.
     *
     * Returns ['verified' => true] or ['verified' => false, 'message' => '...']
     */
    public function verifyPaymentIntent(string $payment_intent_id): array
    {
        try {
            $intent = $this->client->paymentIntents->retrieve($payment_intent_id);

            if ($intent->status !== 'succeeded') {
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
}
