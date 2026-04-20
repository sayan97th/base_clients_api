<?php

namespace App\Services;

use App\Models\Invoice;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class StripePublicPaymentService
{
    private StripeClient $client;
    private StripeService $stripe_service;

    public function __construct(StripeService $stripe_service)
    {
        $this->stripe_service = $stripe_service;
        $this->client = new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Confirm a payment for a public invoice.
     *
     * Verifies:
     * - Invoice exists and shares are enabled
     * - Token matches the public share token
     * - PaymentIntent exists and has succeeded status
     * - PaymentIntent amount matches invoice total
     * - PaymentIntent metadata contains correct invoice_unique_id
     *
     * Returns ['success' => true, 'message' => '...'] or ['success' => false, 'error' => '...', 'status_code' => 400]
     */
    public function confirmPublicInvoicePayment(
        Invoice $invoice,
        string $payment_intent_id,
        string $token
    ): array {
        // Verify sharing is enabled
        if (!$invoice->sharing_enabled) {
            return [
                'success'     => false,
                'error'       => 'Access denied.',
                'status_code' => 403,
            ];
        }

        // Verify token matches the share key
        if ($token !== $invoice->share_key) {
            return [
                'success'     => false,
                'error'       => 'Access denied.',
                'status_code' => 403,
            ];
        }

        // Check if invoice is already paid
        if ($invoice->status === 'paid') {
            return [
                'success'     => false,
                'error'       => 'This invoice has already been paid.',
                'status_code' => 409,
            ];
        }

        // Verify PaymentIntent with Stripe
        try {
            $payment_intent = $this->client->paymentIntents->retrieve($payment_intent_id);
        } catch (ApiErrorException $e) {
            return [
                'success'     => false,
                'error'       => 'Payment verification failed.',
                'status_code' => 400,
            ];
        }

        // Verify payment status
        if ($payment_intent->status !== 'succeeded') {
            return [
                'success'     => false,
                'error'       => 'Payment verification failed.',
                'status_code' => 400,
            ];
        }

        // Verify amount matches (convert to cents for Stripe comparison)
        $invoice_amount_cents = (int) round($invoice->total_amount * 100);
        if ($payment_intent->amount !== $invoice_amount_cents) {
            return [
                'success'     => false,
                'error'       => 'Payment verification failed.',
                'status_code' => 400,
            ];
        }

        // Verify metadata contains correct invoice unique_id
        $metadata = $payment_intent->metadata ?? [];
        if (($metadata['invoice_unique_id'] ?? null) !== $invoice->unique_id) {
            return [
                'success'     => false,
                'error'       => 'Payment verification failed.',
                'status_code' => 400,
            ];
        }

        // All validations passed, mark invoice as paid
        return $this->markInvoiceAsPaid($invoice, $payment_intent_id);
    }

    /**
     * Mark an invoice as paid and record the payment event.
     */
    private function markInvoiceAsPaid(Invoice $invoice, string $payment_intent_id): array
    {
        try {
            $invoice->update([
                'status'          => 'paid',
                'date_paid'       => now(),
                'payment_method'  => 'Credit Card',
            ]);

            // Record the payment in invoice history
            $invoice->history()->create([
                'event'       => 'payment_confirmed',
                'description' => "Payment confirmed via Stripe PaymentIntent: {$payment_intent_id}",
                'actor_type'  => 'system',
            ]);

            return [
                'success'     => true,
                'message'     => 'Payment confirmed successfully.',
                'status'      => 'paid',
                'status_code' => 200,
            ];
        } catch (\Exception $e) {
            return [
                'success'     => false,
                'error'       => 'An error occurred while processing your payment. Please contact support.',
                'status_code' => 500,
            ];
        }
    }
}
