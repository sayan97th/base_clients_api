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
