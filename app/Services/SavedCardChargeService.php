<?php

namespace App\Services;

use App\Jobs\SendAdminInvoicePaidNotificationJob;
use App\Mail\AdminAutopayChargeFailedNotification;
use App\Mail\AutopayChargeFailedClientNotification;
use App\Mail\PaymentSuccessfulEmail;
use App\Models\Invoice;
use App\Models\InvoiceChargeAttempt;
use App\Models\InvoiceHistory;
use App\Models\PaymentProfile;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Concerns\DispatchesAdminPaymentNotifications;
use App\Services\Concerns\TransitionsPaymentPendingOrders;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Charges a client's saved card for an invoice while the client is not present
 * (autopay and the admin "Charge card on file" action).
 *
 * Safety guarantees:
 *  - Only one charge can be in flight per invoice: the attempt row is created
 *    under a row lock on the invoice, and a `processing` attempt blocks others.
 *  - Every attempt carries a Stripe idempotency key, so a retried request can
 *    never produce a second charge for the same attempt.
 *  - The attempt row is written BEFORE Stripe is called. If the process dies
 *    mid-charge the row stays `processing` and reconcileStaleAttempts()
 *    resolves it against Stripe (charged → record it, not charged → fail it).
 *  - If the invoice was paid by another path while the card was being charged,
 *    the new charge is refunded immediately (no double payment).
 */
class SavedCardChargeService
{
    use DispatchesAdminPaymentNotifications, TransitionsPaymentPendingOrders;

    public const MAX_AUTOPAY_ATTEMPTS = 3;

    /** Hours to wait before the next autopay retry, keyed by the failed attempt number. */
    private const RETRY_DELAY_HOURS = [1 => 24, 2 => 72];

    /** A `processing` attempt older than this is reconciled against Stripe. */
    public const STALE_PROCESSING_MINUTES = 15;

    private const PAYABLE_STATUSES = ['unpaid', 'overdue'];

    /**
     * Failures that will not succeed by simply trying again later with the same
     * card — retrying them only produces more declines (and can get the card
     * flagged by the issuer).
     */
    private const NON_RETRYABLE_CODES = [
        'authentication_required',
        'card_not_supported',
        'do_not_try_again',
        'expired_card',
        'fraudulent',
        'incorrect_number',
        'invalid_account',
        'invalid_number',
        'lost_card',
        'merchant_blacklist',
        'no_stripe_customer',
        'payment_method_not_available',
        'pickup_card',
        'resource_missing',
        'restricted_card',
        'revocation_of_all_authorizations',
        'revocation_of_authorization',
        'security_violation',
        'stolen_card',
        'stop_payment_order',
        'transaction_not_allowed',
    ];

    private const ERROR_MESSAGES = [
        'authentication_required'   => 'The card issuer requires the cardholder to authenticate this payment (3D Secure), which is not possible for automatic charges. The client must pay this invoice from the pay page.',
        'no_stripe_customer'        => 'The client has no Stripe customer linked to their account, so the saved card cannot be charged.',
        'expired_card'              => 'The saved card has expired. Please use a different card.',
        'not_submitted'             => 'The charge was interrupted before it reached Stripe. No money was taken.',
        'duplicate_payment_refunded' => 'The invoice was already paid by another method while this charge was running. The duplicate charge was refunded automatically.',
    ];

    public function __construct(private StripeService $stripe_service) {}

    /**
     * Charge the given saved card for the full invoice total.
     *
     * @return array{success: bool, status: string, message: string, status_code: int, attempt: ?InvoiceChargeAttempt}
     */
    public function chargeInvoice(
        Invoice $invoice,
        PaymentProfile $payment_profile,
        string $source,
        ?User $initiated_by = null,
    ): array {
        $invoice_error = $this->validateInvoiceIsChargeable($invoice);

        if ($invoice_error !== null) {
            return $this->result(false, 'not_chargeable', $invoice_error, 422);
        }

        if ((string) $payment_profile->user_id !== (string) $invoice->user_id) {
            return $this->result(false, 'not_chargeable', 'This card does not belong to the invoice owner.', 422);
        }

        $attempt = $this->openAttempt($invoice, $payment_profile, $source, $initiated_by);

        if (is_string($attempt)) {
            return $this->result(false, 'not_chargeable', $attempt, 409);
        }

        // Card-level checks run after the attempt is opened so they are logged.
        $customer_id = $invoice->user?->stripe_customer_id;

        if ($this->isCardExpired($payment_profile)) {
            return $this->failAttempt($attempt, 'expired_card', self::ERROR_MESSAGES['expired_card']);
        }

        if (! $customer_id) {
            return $this->failAttempt($attempt, 'no_stripe_customer', self::ERROR_MESSAGES['no_stripe_customer']);
        }

        $charge_result = $this->stripe_service->chargeSavedCardOffSession(
            amount_cents: (int) round($invoice->total_amount * 100),
            stripe_customer_id: $customer_id,
            stripe_payment_method_id: $payment_profile->stripe_payment_method_id,
            metadata: [
                'invoice_unique_id' => $invoice->unique_id,
                'invoice_id'        => (string) $invoice->id,
                'user_id'           => (string) $invoice->user_id,
                'charge_attempt_id' => (string) $attempt->id,
                'charge_source'     => $source,
            ],
            description: "Invoice {$invoice->invoice_number}" . ($source === InvoiceChargeAttempt::SOURCE_AUTOPAY ? ' (Autopay)' : ' (Card on file)'),
            idempotency_key: $attempt->idempotency_key,
        );

        if (! empty($charge_result['payment_intent_id'])) {
            $attempt->update(['stripe_payment_intent_id' => $charge_result['payment_intent_id']]);
        }

        if ($charge_result['success']) {
            return $this->completeSuccessfulAttempt($attempt);
        }

        if ($charge_result['pending'] ?? false) {
            logger()->warning("Saved-card charge outcome pending for invoice {$invoice->unique_id}", [
                'charge_attempt_id' => $attempt->id,
                'message'           => $charge_result['message'] ?? null,
            ]);

            return $this->result(
                false,
                InvoiceChargeAttempt::STATUS_PROCESSING,
                'The charge is still being processed by Stripe. Its result will be confirmed automatically within a few minutes.',
                202,
                $attempt,
            );
        }

        $error_code = $charge_result['decline_code'] ?: ($charge_result['error_code'] ?? 'card_declined');

        return $this->failAttempt(
            $attempt,
            $error_code,
            self::ERROR_MESSAGES[$error_code]
                ?? StripeService::getUserFriendlyErrorMessage($error_code, $charge_result['message'] ?? 'The card was declined.'),
        );
    }

    /**
     * Resolve attempts left in `processing` (process crash, network timeout,
     * DB failure after the charge) by asking Stripe what actually happened.
     *
     * @return int Number of attempts resolved.
     */
    public function reconcileStaleAttempts(): int
    {
        $stale_attempts = InvoiceChargeAttempt::where('status', InvoiceChargeAttempt::STATUS_PROCESSING)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_PROCESSING_MINUTES))
            ->orderBy('created_at')
            ->get();

        $resolved = 0;

        foreach ($stale_attempts as $attempt) {
            try {
                if ($this->reconcileAttempt($attempt)) {
                    $resolved++;
                }
            } catch (\Throwable $e) {
                logger()->error("Failed to reconcile charge attempt {$attempt->id}", ['error' => $e->getMessage()]);
            }
        }

        return $resolved;
    }

    public function buildCardLabel(?string $brand, ?string $last_four): string
    {
        if (! $last_four) {
            return 'Saved card';
        }

        return ucfirst((string) ($brand ?: 'Card')) . ' •••• ' . $last_four;
    }

    /**
     * Send the "autopay failed / skipped" alerts to every recipient in Email
     * Notification Settings and (optionally) to the client.
     *
     * Mail is sent synchronously on purpose: the alert must arrive even when
     * queue workers are down. Every send is isolated so one bad address never
     * blocks the others.
     */
    public function sendAutopayFailureNotifications(InvoiceChargeAttempt $attempt, bool $notify_client = true): void
    {
        $attempt->loadMissing(['invoice.user']);

        $invoice = $attempt->invoice;
        $client  = $invoice?->user;

        if (! $invoice) {
            return;
        }

        $is_skipped     = $attempt->status === InvoiceChargeAttempt::STATUS_SKIPPED;
        $client_name    = $client?->full_name ?: ($client?->email ?? 'Unknown client');
        $amount         = '$' . number_format((float) $attempt->amount, 2);
        $card_label     = $this->buildCardLabel($attempt->card_brand, $attempt->card_last_four);
        $failure_reason = $attempt->failure_message ?? 'Unknown error.';
        $next_retry_at  = $attempt->next_retry_at?->format('F j, Y \a\t g:i A T');
        $attempt_label  = $is_skipped
            ? 'Not attempted'
            : "{$attempt->attempt_number} (max " . self::MAX_AUTOPAY_ATTEMPTS . ' per card)';

        foreach (EmailNotificationSettingService::resolveAdminRecipients() as $recipient) {
            try {
                Mail::to($recipient['email'])->send(new AdminAutopayChargeFailedNotification(
                    recipient_name:        $recipient['name'],
                    recipient_email:       $recipient['email'],
                    invoice_number:        $invoice->invoice_number,
                    client_name:           $client_name,
                    client_email:          $client?->email ?? '',
                    amount:                $amount,
                    card_label:            $card_label,
                    attempt_label:         $attempt_label,
                    failure_reason:        $failure_reason,
                    failure_code:          $attempt->failure_code,
                    next_retry_at:         $next_retry_at,
                    is_skipped:            $is_skipped,
                    view_invoice_url:      FrontendUrl::to('/admin/invoices/' . $invoice->id),
                    autopay_dashboard_url: FrontendUrl::to('/admin/autopay'),
                    settings_url:          FrontendUrl::to('/admin/email-notifications'),
                ));
            } catch (\Throwable $e) {
                logger()->error('Failed to send autopay failure alert to admin recipient', [
                    'recipient'         => $recipient['email'],
                    'charge_attempt_id' => $attempt->id,
                    'error'             => $e->getMessage(),
                ]);
            }
        }

        if (! $notify_client || ! $client?->email || ! $client->is_active) {
            return;
        }

        try {
            Mail::to($client->email)->send(new AutopayChargeFailedClientNotification(
                client_name:        $client->first_name ?: $client_name,
                client_email:       $client->email,
                invoice_number:     $invoice->invoice_number,
                amount:             $amount,
                card_label:         $card_label,
                failure_reason:     $this->clientFacingReason($attempt),
                next_retry_at:      $next_retry_at,
                is_skipped:         $is_skipped,
                pay_url:            FrontendUrl::to('/invoices/' . $invoice->unique_id . '/pay'),
                manage_autopay_url: FrontendUrl::to('/invoices'),
            ));
        } catch (\Throwable $e) {
            logger()->error('Failed to send autopay failure email to client', [
                'charge_attempt_id' => $attempt->id,
                'error'             => $e->getMessage(),
            ]);
        }
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    private function validateInvoiceIsChargeable(Invoice $invoice): ?string
    {
        if (! in_array($invoice->status, self::PAYABLE_STATUSES, true)) {
            return 'This invoice cannot be charged in its current status.';
        }

        if ($invoice->currency_type !== 'usd') {
            return 'Only USD invoices can be charged to a card.';
        }

        if ((float) $invoice->total_amount < 0.5) {
            return 'The invoice total is below the minimum card charge ($0.50).';
        }

        return null;
    }

    /**
     * Open a `processing` attempt under a row lock on the invoice.
     * Returns the attempt, or an error message when the invoice cannot be charged right now.
     */
    private function openAttempt(
        Invoice $invoice,
        PaymentProfile $payment_profile,
        string $source,
        ?User $initiated_by,
    ): InvoiceChargeAttempt|string {
        return DB::transaction(function () use ($invoice, $payment_profile, $source, $initiated_by) {
            $locked_invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            if (! $locked_invoice || ! in_array($locked_invoice->status, self::PAYABLE_STATUSES, true)) {
                return 'This invoice has already been paid or is no longer payable.';
            }

            $has_in_flight_charge = InvoiceChargeAttempt::where('invoice_id', $invoice->id)
                ->whereIn('status', [InvoiceChargeAttempt::STATUS_PROCESSING, InvoiceChargeAttempt::STATUS_REQUIRES_REVIEW])
                ->exists();

            if ($has_in_flight_charge) {
                return 'Another charge for this invoice is already in progress or awaiting review.';
            }

            $attempt_number = InvoiceChargeAttempt::where('invoice_id', $invoice->id)
                ->where('source', $source)
                ->where('status', '!=', InvoiceChargeAttempt::STATUS_SKIPPED)
                ->count() + 1;

            return InvoiceChargeAttempt::create([
                'invoice_id'         => $invoice->id,
                'user_id'            => $invoice->user_id,
                'payment_profile_id' => $payment_profile->id,
                'source'             => $source,
                'attempt_number'     => $attempt_number,
                'status'             => InvoiceChargeAttempt::STATUS_PROCESSING,
                'amount'             => $locked_invoice->total_amount,
                'card_brand'         => $payment_profile->card_brand,
                'card_last_four'     => $payment_profile->last_four,
                'idempotency_key'    => "invoice-charge:{$invoice->id}:{$source}:{$attempt_number}",
                'initiated_by_id'    => $initiated_by?->id,
                'initiated_by_name'  => $initiated_by ? ($initiated_by->full_name ?: $initiated_by->email) : 'System (Autopay)',
            ]);
        });
    }

    private function completeSuccessfulAttempt(InvoiceChargeAttempt $attempt): array
    {
        $payment_intent_id = $attempt->stripe_payment_intent_id;
        $card_label        = $this->buildCardLabel($attempt->card_brand, $attempt->card_last_four);
        $is_autopay        = $attempt->source === InvoiceChargeAttempt::SOURCE_AUTOPAY;

        try {
            $outcome = DB::transaction(function () use ($attempt, $payment_intent_id, $card_label, $is_autopay) {
                $invoice = Invoice::whereKey($attempt->invoice_id)->lockForUpdate()->first();

                if (! $invoice || ! in_array($invoice->status, self::PAYABLE_STATUSES, true)) {
                    return 'already_paid';
                }

                $invoice->update([
                    'status'            => 'paid',
                    'date_paid'         => now(),
                    'payment_method'    => 'Credit Card',
                    'payment_intent_id' => $payment_intent_id,
                ]);

                $amount_label = '$' . number_format((float) $attempt->amount, 2);

                InvoiceHistory::create([
                    'invoice_id'     => $invoice->id,
                    'event'          => $is_autopay ? 'autopay charge succeeded' : 'charged card on file',
                    'description'    => ($is_autopay
                        ? "Invoice paid automatically via Autopay — {$card_label} charged {$amount_label}."
                        : "Card on file ({$card_label}) charged {$amount_label}.")
                        . " PaymentIntent: {$payment_intent_id}",
                    'actor_id'       => $attempt->initiated_by_id,
                    'actor_name'     => $attempt->initiated_by_name ?? 'System',
                    'actor_initials' => $is_autopay ? 'AP' : $this->buildInitials($attempt->initiated_by_name ?? 'System'),
                    'actor_type'     => $is_autopay ? 'system' : 'admin',
                ]);

                Transaction::create([
                    'user_id'           => $invoice->user_id,
                    'type'              => 'purchase',
                    'status'            => 'success',
                    'amount'            => $invoice->total_amount,
                    'payment_method'    => 'credit_card',
                    'payment_intent_id' => $payment_intent_id,
                    'invoice_id'        => (string) $invoice->id,
                    'description'       => "Invoice {$invoice->invoice_number} paid via "
                        . ($is_autopay ? 'Autopay' : 'card on file') . " ({$card_label}).",
                    'metadata'          => [
                        'charge_source'     => $attempt->source,
                        'charge_attempt_id' => (string) $attempt->id,
                    ],
                ]);

                $attempt->update([
                    'status'          => InvoiceChargeAttempt::STATUS_SUCCEEDED,
                    'failure_code'    => null,
                    'failure_message' => null,
                    'is_retryable'    => false,
                    'next_retry_at'   => null,
                    'completed_at'    => now(),
                ]);

                return 'paid';
            });
        } catch (\Throwable $e) {
            // The card WAS charged but the DB write failed. The attempt stays
            // `processing` with its PaymentIntent id, so the reconciler records
            // the payment on its next run — nothing is lost and nothing is re-charged.
            logger()->critical("Card charged but invoice could not be recorded — will be reconciled (attempt {$attempt->id})", [
                'payment_intent_id' => $payment_intent_id,
                'error'             => $e->getMessage(),
            ]);

            return $this->result(
                false,
                InvoiceChargeAttempt::STATUS_PROCESSING,
                'The card was charged but the invoice could not be updated yet. It will be updated automatically within a few minutes.',
                202,
                $attempt,
            );
        }

        if ($outcome === 'already_paid') {
            return $this->refundDuplicateCharge($attempt);
        }

        $invoice = Invoice::with(['user', 'lineItems'])->find($attempt->invoice_id);

        $this->runPostPaymentSideEffects($invoice, $payment_intent_id);

        return $this->result(
            true,
            InvoiceChargeAttempt::STATUS_SUCCEEDED,
            "Payment of $" . number_format((float) $attempt->amount, 2) . " charged to {$card_label}.",
            200,
            $attempt->fresh(),
        );
    }

    /**
     * The invoice was settled through another path while this charge ran
     * (e.g. the client paid on the pay page at the same moment). Refund the
     * extra charge right away so the client is never billed twice.
     */
    private function refundDuplicateCharge(InvoiceChargeAttempt $attempt): array
    {
        $refund_result = $this->stripe_service->refundPaymentIntent(
            $attempt->stripe_payment_intent_id,
            'duplicate'
        );

        if ($refund_result['success']) {
            $attempt->update([
                'status'          => InvoiceChargeAttempt::STATUS_FAILED,
                'failure_code'    => 'duplicate_payment_refunded',
                'failure_message' => self::ERROR_MESSAGES['duplicate_payment_refunded'],
                'is_retryable'    => false,
                'next_retry_at'   => null,
                'completed_at'    => now(),
            ]);

            return $this->result(false, InvoiceChargeAttempt::STATUS_FAILED, self::ERROR_MESSAGES['duplicate_payment_refunded'], 409, $attempt);
        }

        logger()->critical("Duplicate charge could NOT be refunded automatically (attempt {$attempt->id})", [
            'payment_intent_id' => $attempt->stripe_payment_intent_id,
            'error'             => $refund_result['message'] ?? null,
        ]);

        $attempt->update([
            'status'          => InvoiceChargeAttempt::STATUS_REQUIRES_REVIEW,
            'failure_code'    => 'duplicate_payment_refund_failed',
            'failure_message' => 'The invoice was already paid and the duplicate charge could not be refunded automatically. Refund it manually in Stripe.',
            'completed_at'    => now(),
        ]);

        $this->sendAutopayFailureNotifications($attempt, notify_client: false);

        return $this->result(false, InvoiceChargeAttempt::STATUS_REQUIRES_REVIEW, $attempt->failure_message, 409, $attempt);
    }

    private function failAttempt(
        InvoiceChargeAttempt $attempt,
        string $failure_code,
        string $failure_message,
        bool $notify_client = true,
    ): array {
        $is_autopay   = $attempt->source === InvoiceChargeAttempt::SOURCE_AUTOPAY;
        $is_retryable = ! in_array($failure_code, self::NON_RETRYABLE_CODES, true);
        $retry_hours  = self::RETRY_DELAY_HOURS[$attempt->attempt_number] ?? null;

        $next_retry_at = ($is_autopay && $is_retryable && $retry_hours !== null)
            ? now()->addHours($retry_hours)
            : null;

        $card_label = $this->buildCardLabel($attempt->card_brand, $attempt->card_last_four);

        DB::transaction(function () use ($attempt, $failure_code, $failure_message, $is_retryable, $next_retry_at, $card_label, $is_autopay) {
            $attempt->update([
                'status'          => InvoiceChargeAttempt::STATUS_FAILED,
                'failure_code'    => $failure_code,
                'failure_message' => $failure_message,
                'is_retryable'    => $is_retryable && $next_retry_at !== null,
                'next_retry_at'   => $next_retry_at,
                'completed_at'    => now(),
            ]);

            InvoiceHistory::create([
                'invoice_id'     => $attempt->invoice_id,
                'event'          => $is_autopay ? 'autopay charge failed' : 'card on file charge failed',
                'description'    => "Charge to {$card_label} failed: {$failure_message} [{$failure_code}]"
                    . ($next_retry_at ? ' Next automatic retry: ' . $next_retry_at->format('M j, Y g:i A') . '.' : ''),
                'actor_id'       => $attempt->initiated_by_id,
                'actor_name'     => $attempt->initiated_by_name ?? 'System',
                'actor_initials' => $is_autopay ? 'AP' : $this->buildInitials($attempt->initiated_by_name ?? 'System'),
                'actor_type'     => $is_autopay ? 'system' : 'admin',
            ]);

            Transaction::create([
                'user_id'           => $attempt->user_id,
                'type'              => 'failed_purchase',
                'status'            => 'failed',
                'amount'            => $attempt->amount,
                'payment_method'    => 'credit_card',
                'payment_intent_id' => $attempt->stripe_payment_intent_id,
                'invoice_id'        => (string) $attempt->invoice_id,
                'description'       => ($is_autopay ? 'Autopay' : 'Card on file') . " charge failed ({$card_label}).",
                'error_message'     => $failure_message,
                'metadata'          => [
                    'charge_source'     => $attempt->source,
                    'charge_attempt_id' => (string) $attempt->id,
                    'failure_code'      => $failure_code,
                ],
            ]);
        });

        // Admin-initiated charges report the error straight back in the UI;
        // only automatic charges need out-of-band alerts.
        if ($is_autopay) {
            $this->sendAutopayFailureNotifications($attempt->fresh(), $notify_client);
        }

        return $this->result(false, InvoiceChargeAttempt::STATUS_FAILED, $failure_message, 402, $attempt->fresh());
    }

    private function reconcileAttempt(InvoiceChargeAttempt $attempt): bool
    {
        if ($attempt->stripe_payment_intent_id) {
            $lookup = $this->stripe_service->retrievePaymentIntent($attempt->stripe_payment_intent_id);
        } else {
            $lookup = $this->stripe_service->findPaymentIntentByMetadata('charge_attempt_id', (string) $attempt->id);
        }

        if (! $lookup['success']) {
            // Stripe unreachable — try again on the next run.
            return false;
        }

        $intent = $lookup['payment_intent'];

        if ($intent === null) {
            // Stripe never received the request: no money moved.
            $this->failAttempt($attempt, 'not_submitted', self::ERROR_MESSAGES['not_submitted'], notify_client: false);

            return true;
        }

        if (! $attempt->stripe_payment_intent_id) {
            $attempt->update(['stripe_payment_intent_id' => $intent['id']]);
        }

        switch ($intent['status']) {
            case 'succeeded':
                $this->completeSuccessfulAttempt($attempt);

                return true;

            case 'requires_payment_method':
            case 'canceled':
                $error_code = $intent['last_error_code'] ?? 'card_declined';

                $this->failAttempt(
                    $attempt,
                    $error_code,
                    StripeService::getUserFriendlyErrorMessage($error_code, $intent['last_error_message'] ?? 'The card was declined.'),
                );

                return true;

            case 'requires_action':
                $this->failAttempt($attempt, 'authentication_required', self::ERROR_MESSAGES['authentication_required']);

                return true;

            case 'processing':
                // Still in flight at Stripe; give it up to a day before escalating.
                if ($attempt->created_at->lt(now()->subDay())) {
                    $this->markRequiresReview($attempt, 'The charge has been processing at Stripe for over 24 hours.');

                    return true;
                }

                return false;

            default:
                $this->markRequiresReview($attempt, "Unexpected PaymentIntent status: {$intent['status']}.");

                return true;
        }
    }

    private function markRequiresReview(InvoiceChargeAttempt $attempt, string $reason): void
    {
        $attempt->update([
            'status'          => InvoiceChargeAttempt::STATUS_REQUIRES_REVIEW,
            'failure_code'    => 'requires_review',
            'failure_message' => $reason,
            'is_retryable'    => false,
            'next_retry_at'   => null,
        ]);

        $this->sendAutopayFailureNotifications($attempt->fresh(), notify_client: false);
    }

    /**
     * Non-critical follow-ups once the payment is recorded. Failures are
     * logged and never undo the payment.
     */
    private function runPostPaymentSideEffects(Invoice $invoice, ?string $payment_intent_id): void
    {
        try {
            $this->updatePaymentPendingOrders($invoice, $payment_intent_id);
        } catch (\Throwable $e) {
            logger()->error("Failed to transition payment_pending orders for invoice {$invoice->unique_id}", [
                'error' => $e->getMessage(),
            ]);
        }

        $client = $invoice->user;

        try {
            if ($client?->email) {
                Mail::to($client->email)->queue(new PaymentSuccessfulEmail($client, $invoice));
            }
        } catch (\Throwable $e) {
            logger()->warning("Failed to queue payment confirmation email for invoice {$invoice->unique_id}", [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            SendAdminInvoicePaidNotificationJob::dispatch($invoice->id);

            $payer_name = $client?->full_name ?: ($client?->email ?? 'A client');

            $this->dispatchAdminPaymentCompletedEvent($invoice, $payer_name, (float) $invoice->total_amount);
        } catch (\Throwable $e) {
            logger()->warning("Failed to dispatch admin payment notifications for invoice {$invoice->unique_id}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function clientFacingReason(InvoiceChargeAttempt $attempt): string
    {
        return match ($attempt->failure_code) {
            'authentication_required' => 'Your bank requires you to confirm this payment. Please pay the invoice from the payment page.',
            'exceeds_autopay_limit'   => $attempt->failure_message ?? 'The invoice exceeds your autopay limit.',
            'no_stripe_customer'      => 'We could not access your saved card. Please pay the invoice manually or add your card again.',
            default                   => $attempt->failure_message ?? 'Your card was declined.',
        };
    }

    private function isCardExpired(PaymentProfile $payment_profile): bool
    {
        $year  = (int) $payment_profile->expiry_year;
        $month = (int) $payment_profile->expiry_month;

        if ($year <= 0 || $month <= 0) {
            return false;
        }

        if ($year < 100) {
            $year += 2000;
        }

        return now()->startOfMonth()->gt(now()->setDate($year, $month, 1)->startOfMonth());
    }

    private function buildInitials(string $name): string
    {
        $parts = array_filter(explode(' ', trim($name)));

        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
        }

        return strtoupper(mb_substr($name, 0, 2));
    }

    private function result(
        bool $success,
        string $status,
        string $message,
        int $status_code,
        ?InvoiceChargeAttempt $attempt = null,
    ): array {
        return [
            'success'     => $success,
            'status'      => $status,
            'message'     => $message,
            'status_code' => $status_code,
            'attempt'     => $attempt,
        ];
    }
}
