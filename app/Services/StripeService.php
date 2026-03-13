<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\User;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;
use Stripe\SetupIntent;
use Stripe\StripeClient;

class StripeService
{
    private StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Create or retrieve the Stripe customer for a user.
     * Persists the customer ID to the user record.
     */
    public function createOrGetCustomer(User $user): string
    {
        if ($user->stripe_customer_id) {
            return $user->stripe_customer_id;
        }

        $customer = $this->stripe->customers->create([
            'email'    => $user->email,
            'name'     => $user->full_name,
            'metadata' => ['user_id' => $user->id],
        ]);

        $user->update(['stripe_customer_id' => $customer->id]);

        return $customer->id;
    }

    /**
     * Create a SetupIntent so the frontend can securely tokenize a card
     * without immediately charging it.
     *
     * @return array{client_secret: string}
     */
    public function createSetupIntent(User $user): array
    {
        $customer_id = $this->createOrGetCustomer($user);

        $setup_intent = $this->stripe->setupIntents->create([
            'customer'             => $customer_id,
            'payment_method_types' => ['card'],
            'usage'                => 'off_session',
        ]);

        return ['client_secret' => $setup_intent->client_secret];
    }

    /**
     * Attach a tokenized Stripe PaymentMethod (pm_xxx) to the user and
     * persist the card details in our local payment_methods table.
     *
     * Call this AFTER the frontend confirms the SetupIntent or after
     * receiving a new pm_xxx from Stripe Elements.
     *
     * @throws ApiErrorException
     */
    public function attachPaymentMethod(
        User $user,
        string $stripe_payment_method_id,
        bool $set_as_default = false
    ): PaymentMethod {
        $customer_id = $this->createOrGetCustomer($user);

        $stripe_pm = $this->stripe->paymentMethods->retrieve($stripe_payment_method_id);

        // Attach to the Stripe customer if not already attached
        if (empty($stripe_pm->customer)) {
            $this->stripe->paymentMethods->attach($stripe_payment_method_id, [
                'customer' => $customer_id,
            ]);
        }

        // If this is the first card or explicitly set as default, update Stripe customer
        $is_first_card = !PaymentMethod::where('user_id', $user->id)->exists();
        $should_be_default = $set_as_default || $is_first_card;

        if ($should_be_default) {
            $this->stripe->customers->update($customer_id, [
                'invoice_settings' => [
                    'default_payment_method' => $stripe_payment_method_id,
                ],
            ]);
            PaymentMethod::where('user_id', $user->id)->update(['is_default' => false]);
        }

        $card = $stripe_pm->card;

        return PaymentMethod::create([
            'user_id'                  => $user->id,
            'stripe_payment_method_id' => $stripe_payment_method_id,
            'card_brand'               => $card->brand,
            'card_last_four'           => $card->last4,
            'card_exp_month'           => $card->exp_month,
            'card_exp_year'            => $card->exp_year,
            'cardholder_name'          => $stripe_pm->billing_details->name,
            'billing_zip'              => $stripe_pm->billing_details->address->postal_code ?? null,
            'is_default'               => $should_be_default,
        ]);
    }

    /**
     * Set a saved payment method as the user's default.
     *
     * @throws ApiErrorException
     */
    public function setDefaultPaymentMethod(User $user, PaymentMethod $payment_method): void
    {
        $customer_id = $this->createOrGetCustomer($user);

        $this->stripe->customers->update($customer_id, [
            'invoice_settings' => [
                'default_payment_method' => $payment_method->stripe_payment_method_id,
            ],
        ]);

        PaymentMethod::where('user_id', $user->id)->update(['is_default' => false]);
        $payment_method->update(['is_default' => true]);
    }

    /**
     * Detach a payment method from the Stripe customer and delete it locally.
     *
     * @throws ApiErrorException
     */
    public function detachPaymentMethod(User $user, PaymentMethod $payment_method): void
    {
        $this->stripe->paymentMethods->detach($payment_method->stripe_payment_method_id);

        $was_default = $payment_method->is_default;
        $user_id     = $payment_method->user_id;

        $payment_method->delete();

        // Promote the next card to default if the removed one was the default
        if ($was_default) {
            $next = PaymentMethod::where('user_id', $user_id)->first();
            if ($next) {
                $this->setDefaultPaymentMethod($user, $next);
            }
        }
    }

    /**
     * Create and immediately confirm a PaymentIntent.
     *
     * Returns an array with the result status:
     *   - status = 'succeeded'       → payment completed, use charge_id for records
     *   - status = 'requires_action' → 3D Secure required, expose client_secret to frontend
     *   - status = 'failed'          → card declined, error message included
     *
     * @param  int    $amount_cents       Amount in smallest currency unit (e.g. cents for USD)
     * @param  bool   $off_session        True when user is not actively present (saved card re-use)
     *
     * @return array{
     *   status: string,
     *   payment_intent_id: string,
     *   charge_id: string|null,
     *   client_secret: string|null,
     *   error: string|null
     * }
     *
     * @throws ApiErrorException
     */
    public function processPayment(
        User $user,
        int $amount_cents,
        string $stripe_payment_method_id,
        string $currency = 'usd',
        string $description = '',
        bool $off_session = false
    ): array {
        $customer_id = $this->createOrGetCustomer($user);

        try {
            $params = [
                'amount'               => $amount_cents,
                'currency'             => $currency,
                'customer'             => $customer_id,
                'payment_method'       => $stripe_payment_method_id,
                'description'          => $description,
                'confirmation_method'  => 'automatic',
                'confirm'              => true,
                'return_url'           => config('app.frontend_url') . '/billing/payment/callback',
            ];

            if ($off_session) {
                $params['off_session'] = true;
            }

            $intent = $this->stripe->paymentIntents->create($params);

            return $this->resolvePaymentIntentResult($intent);
        } catch (CardException $e) {
            return [
                'status'            => 'failed',
                'payment_intent_id' => $e->getError()->payment_intent->id ?? null,
                'charge_id'         => null,
                'client_secret'     => null,
                'error'             => $e->getMessage(),
            ];
        }
    }

    /**
     * Retrieve a PaymentIntent by ID (used during webhook or polling).
     *
     * @throws ApiErrorException
     */
    public function retrievePaymentIntent(string $payment_intent_id): PaymentIntent
    {
        return $this->stripe->paymentIntents->retrieve($payment_intent_id, [
            'expand' => ['latest_charge'],
        ]);
    }

    /**
     * Construct a Stripe webhook event from the raw request payload and
     * verify the signature using the webhook secret.
     *
     * @throws \Stripe\Exception\SignatureVerificationException
     */
    public function constructWebhookEvent(string $payload, string $signature): \Stripe\Event
    {
        return \Stripe\Webhook::constructEvent(
            $payload,
            $signature,
            config('services.stripe.webhook_secret')
        );
    }

    /**
     * Translate a confirmed PaymentIntent into a standard result array.
     */
    private function resolvePaymentIntentResult(PaymentIntent $intent): array
    {
        return match ($intent->status) {
            'succeeded' => [
                'status'            => 'succeeded',
                'payment_intent_id' => $intent->id,
                'charge_id'         => $intent->latest_charge,
                'client_secret'     => null,
                'error'             => null,
            ],
            'requires_action', 'requires_confirmation' => [
                'status'            => 'requires_action',
                'payment_intent_id' => $intent->id,
                'charge_id'         => null,
                'client_secret'     => $intent->client_secret,
                'error'             => null,
            ],
            default => [
                'status'            => 'failed',
                'payment_intent_id' => $intent->id,
                'charge_id'         => null,
                'client_secret'     => null,
                'error'             => 'Payment could not be processed. Status: ' . $intent->status,
            ],
        };
    }
}
