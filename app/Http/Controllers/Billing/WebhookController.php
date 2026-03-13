<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\StripeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;

class WebhookController extends Controller
{
    public function __construct(
        protected StripeService $stripeService
    ) {}

    /**
     * POST /stripe/webhook
     *
     * Handles incoming Stripe webhook events. This route must be excluded
     * from the auth:api middleware and CSRF protection.
     *
     * Configure your Stripe webhook to send:
     *   - payment_intent.succeeded
     *   - payment_intent.payment_failed
     *   - setup_intent.succeeded
     *   - customer.deleted
     */
    public function handle(Request $request): Response
    {
        $payload   = $request->getContent();
        $signature = $request->header('Stripe-Signature');

        if (!$signature) {
            Log::warning('Stripe webhook received without signature header.');
            return response('Missing signature.', 400);
        }

        try {
            $event = $this->stripeService->constructWebhookEvent($payload, $signature);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed: ' . $e->getMessage());
            return response('Invalid signature.', 400);
        }

        match ($event->type) {
            'payment_intent.succeeded'        => $this->handlePaymentIntentSucceeded($event->data->object),
            'payment_intent.payment_failed'   => $this->handlePaymentIntentFailed($event->data->object),
            'setup_intent.succeeded'          => $this->handleSetupIntentSucceeded($event->data->object),
            'customer.deleted'                => $this->handleCustomerDeleted($event->data->object),
            default                           => null,
        };

        return response('Webhook received.', 200);
    }

    /**
     * A PaymentIntent has been successfully charged.
     * Update any pending invoices linked to this PaymentIntent.
     */
    private function handlePaymentIntentSucceeded(\Stripe\PaymentIntent $intent): void
    {
        $invoice = Invoice::where('stripe_payment_intent_id', $intent->id)->first();

        if (!$invoice) {
            return;
        }

        if ($invoice->status === 'paid') {
            return;
        }

        $charge_id = $intent->latest_charge ?? null;
        if (is_object($charge_id)) {
            $charge_id = $charge_id->id;
        }

        $invoice->update([
            'status'            => 'paid',
            'stripe_charge_id'  => $charge_id,
            'date_paid'         => now(),
        ]);

        // Update the related order status to 'processing'
        if ($invoice->order) {
            $invoice->order->update(['status' => 'processing']);
        }

        Log::info('Invoice ' . $invoice->invoice_number . ' marked as paid via webhook.', [
            'payment_intent_id' => $intent->id,
            'charge_id'         => $charge_id,
        ]);
    }

    /**
     * A PaymentIntent has failed (card declined, insufficient funds, etc.).
     * Mark the related invoice as void so the user can retry.
     */
    private function handlePaymentIntentFailed(\Stripe\PaymentIntent $intent): void
    {
        $invoice = Invoice::where('stripe_payment_intent_id', $intent->id)->first();

        if (!$invoice || $invoice->status !== 'pending') {
            return;
        }

        $error_message = $intent->last_payment_error?->message ?? 'Payment failed.';

        $invoice->update(['status' => 'void']);

        Log::warning('Invoice ' . $invoice->invoice_number . ' voided due to payment failure.', [
            'payment_intent_id' => $intent->id,
            'error'             => $error_message,
        ]);
    }

    /**
     * A SetupIntent has completed successfully.
     * The customer's card has been saved in Stripe.
     * No local action needed here — the frontend calls POST /billing/payment-methods
     * after setup intent confirmation.
     */
    private function handleSetupIntentSucceeded(\Stripe\SetupIntent $intent): void
    {
        Log::info('SetupIntent succeeded for customer: ' . $intent->customer, [
            'payment_method' => $intent->payment_method,
        ]);
    }

    /**
     * A Stripe customer was deleted (e.g., from the Stripe dashboard).
     * Clear the stripe_customer_id from our user record.
     */
    private function handleCustomerDeleted(\Stripe\Customer $customer): void
    {
        \App\Models\User::where('stripe_customer_id', $customer->id)
            ->update(['stripe_customer_id' => null]);

        Log::info('Stripe customer deleted: ' . $customer->id);
    }
}
