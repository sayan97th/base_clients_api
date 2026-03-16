<?php

namespace App\Services;

use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class StripeService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Create and confirm a PaymentIntent for the given amount.
     *
     * Returns ['success' => true,  'payment_intent_id' => 'pi_...']
     *      or ['success' => false, 'message' => '...']
     */
    public function createPaymentIntent(float $amount_usd, string $payment_method_id, array $metadata = []): array
    {
        try {
            $intent = PaymentIntent::create([
                'amount'                     => (int) round($amount_usd * 100), // convert to cents
                'currency'                   => 'usd',
                'payment_method'             => $payment_method_id,
                'confirm'                    => true,
                'automatic_payment_methods'  => [
                    'enabled'         => true,
                    'allow_redirects' => 'never',
                ],
                'metadata' => $metadata,
            ]);

            return [
                'success'           => true,
                'payment_intent_id' => $intent->id,
                'status'            => $intent->status,
            ];
        } catch (CardException $e) {
            return [
                'success' => false,
                'message' => $e->getError()->message ?? 'Your card was declined.',
            ];
        } catch (ApiErrorException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
