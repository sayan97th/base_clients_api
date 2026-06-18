<?php

namespace App\Services;

use App\Events\PaymentCompleted;
use App\Jobs\SendAdminInvoicePaidNotificationJob;
use App\Mail\PaymentSuccessfulEmail;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
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

        // Reject any status that cannot be paid
        if (! in_array($invoice->status, ['unpaid', 'overdue'], true)) {
            return [
                'success'     => false,
                'error'       => 'This invoice cannot be paid in its current status.',
                'status_code' => 400,
            ];
        }

        // Verify PaymentIntent with Stripe
        try {
            $payment_intent = $this->client->paymentIntents->retrieve($payment_intent_id);
        } catch (ApiErrorException $e) {
            return [
                'success'     => false,
                'error'       => 'Payment verification failed.',
                'status_code' => 402,
            ];
        }

        // Verify payment status
        if ($payment_intent->status !== 'succeeded') {
            return [
                'success'     => false,
                'error'       => 'Payment verification failed. The payment was not completed successfully.',
                'status_code' => 402,
            ];
        }

        // Verify amount matches (convert to cents for Stripe comparison)
        $invoice_amount_cents = (int) round($invoice->total_amount * 100);
        if ($payment_intent->amount !== $invoice_amount_cents) {
            return [
                'success'     => false,
                'error'       => 'Payment verification failed.',
                'status_code' => 402,
            ];
        }

        // Verify metadata contains correct invoice unique_id
        $metadata = $payment_intent->metadata ?? [];
        if (($metadata['invoice_unique_id'] ?? null) !== $invoice->unique_id) {
            return [
                'success'     => false,
                'error'       => 'Payment verification failed.',
                'status_code' => 402,
            ];
        }

        // All validations passed, mark invoice as paid
        return $this->markInvoiceAsPaid($invoice, $payment_intent_id);
    }

    /**
     * Mark an invoice as paid and record the payment event.
     * Core DB operations are isolated from notification side-effects so a broken
     * email/notification service never rolls back a genuine payment.
     */
    private function markInvoiceAsPaid(Invoice $invoice, string $payment_intent_id): array
    {
        try {
            $invoice->update([
                'status'            => 'paid',
                'date_paid'         => now(),
                'payment_method'    => 'Credit Card',
                'payment_intent_id' => $payment_intent_id,
            ]);

            $invoice->history()->create([
                'event'       => 'payment_confirmed',
                'description' => "Payment confirmed via Stripe PaymentIntent: {$payment_intent_id}",
                'actor_type'  => 'system',
            ]);
        } catch (\Exception $e) {
            logger()->error("Failed to mark invoice {$invoice->unique_id} as paid", [
                'payment_intent_id' => $payment_intent_id,
                'error'             => $e->getMessage(),
            ]);

            return [
                'success'     => false,
                'error'       => 'An error occurred while processing your payment. Please contact support.',
                'status_code' => 500,
            ];
        }

        // Non-critical: send notifications. Failures are logged but do not affect payment status.
        $this->sendPaymentSuccessfulEmail($invoice);
        $this->sendAdminNotifications($invoice);

        return [
            'success'     => true,
            'message'     => 'Payment confirmed successfully.',
            'status'      => 'paid',
            'status_code' => 200,
        ];
    }

    /**
     * Send payment confirmation email to the client.
     */
    private function sendPaymentSuccessfulEmail(Invoice $invoice): void
    {
        try {
            $user = $invoice->user;

            if ($user && $user->email) {
                Mail::to($user->email)->queue(new PaymentSuccessfulEmail($user, $invoice));
            }
        } catch (\Exception $e) {
            logger()->warning("Failed to send payment confirmation email for invoice {$invoice->unique_id}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify all configured admin recipients that an invoice has been paid via public link.
     * Dispatches an email job (respecting Email Settings) and fires in-app notifications.
     */
    private function sendAdminNotifications(Invoice $invoice): void
    {
        try {
            // Email to all recipients configured in /admin/email-notifications
            SendAdminInvoicePaidNotificationJob::dispatch($invoice->id);

            // In-app portal notifications for admin/super_admin users
            $payer_name = $invoice->user?->full_name ?? $invoice->user?->email ?? 'A client';

            User::whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->where('is_active', true)
                ->each(function (User $admin) use ($invoice, $payer_name) {
                    event(new PaymentCompleted(
                        user:           $admin,
                        payer_name:     $payer_name,
                        amount:         (float) $invoice->total_amount,
                        invoice_number: $invoice->invoice_number,
                        link:           '/admin/invoices/' . $invoice->id,
                        invoice:        $invoice,
                    ));
                });
        } catch (\Exception $e) {
            logger()->warning("Failed to send admin notifications for invoice {$invoice->unique_id}", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
