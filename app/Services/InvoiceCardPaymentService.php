<?php

namespace App\Services;

use App\Jobs\SendAdminInvoicePaidNotificationJob;
use App\Mail\PaymentSuccessfulEmail;
use App\Models\ContentBriefOrder;
use App\Models\ContentOptimizationOrder;
use App\Models\Invoice;
use App\Models\LinkBuildingOrder;
use App\Models\NewContentOrder;
use App\Models\PaymentProfile;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Concerns\DispatchesAdminPaymentNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Card payments for invoices: PaymentIntent creation for the invoice pay page
 * (new card or saved card) and off-session "charge card on file" for admins.
 *
 * Amounts are always derived from the invoice record — never from the client —
 * and every PaymentIntent is card-only with manual capture, so a card is only
 * charged once the invoice has been recorded as paid.
 */
class InvoiceCardPaymentService
{
    use DispatchesAdminPaymentNotifications;

    public const PAYABLE_STATUSES = ['unpaid', 'overdue'];

    /** Stripe's minimum charge amount for USD, in cents. */
    private const MINIMUM_AMOUNT_CENTS = 50;

    private const ORDER_MODELS = [
        LinkBuildingOrder::class,
        NewContentOrder::class,
        ContentOptimizationOrder::class,
        ContentBriefOrder::class,
    ];

    public function __construct(
        protected StripeService $stripe_service,
        protected OrderDetailsService $order_details_service,
    ) {}

    /**
     * Returns null when the invoice can be paid by card, or an error array
     * ['message' => '...', 'status_code' => int] explaining why it cannot.
     */
    public function validateCardPayable(Invoice $invoice): ?array
    {
        if (! in_array($invoice->status, self::PAYABLE_STATUSES, true)) {
            return ['message' => 'This invoice cannot be paid in its current status.', 'status_code' => 400];
        }

        if ($invoice->currency_type !== 'usd') {
            return ['message' => 'This invoice is denominated in account credits and cannot be paid by card.', 'status_code' => 400];
        }

        if ($this->toCents((float) $invoice->total_amount) < self::MINIMUM_AMOUNT_CENTS) {
            return ['message' => 'This invoice amount is below the minimum card charge.', 'status_code' => 400];
        }

        return null;
    }

    /**
     * Create a card-only PaymentIntent for the invoice pay page.
     *
     * $user is the authenticated owner (null for public share-link payments).
     * $payment_profile is a saved card owned by $user, or null for a new card.
     * $save_card sets the new card up for future use (authenticated only).
     *
     * Returns ['success' => true, 'client_secret' => '...', 'payment_intent_id' => '...']
     *      or ['success' => false, 'message' => '...', 'status_code' => int]
     */
    public function createPaymentIntent(
        Invoice $invoice,
        ?User $user,
        ?PaymentProfile $payment_profile = null,
        bool $save_card = false
    ): array {
        $payable_error = $this->validateCardPayable($invoice);

        if ($payable_error !== null) {
            return ['success' => false, ...$payable_error];
        }

        $stripe_customer_id       = null;
        $stripe_payment_method_id = null;

        if ($payment_profile !== null && $user !== null) {
            $customer_result = $this->resolvePaymentProfileCustomer($payment_profile, $user);

            if (! $customer_result['success']) {
                return ['success' => false, 'message' => $customer_result['message'], 'status_code' => 422];
            }

            $stripe_customer_id       = $customer_result['customer_id'];
            $stripe_payment_method_id = $payment_profile->stripe_payment_method_id;
        } elseif ($user !== null) {
            // Attach the customer for authenticated payers so a new card can be
            // saved to their account after checkout.
            $customer_result    = $this->stripe_service->findOrCreateCustomer($user);
            $stripe_customer_id = $customer_result['success'] ? $customer_result['customer_id'] : null;
        }

        $result = $this->stripe_service->createInvoiceCardPaymentIntent(
            amount_cents: $this->toCents((float) $invoice->total_amount),
            metadata: $this->buildMetadata($invoice, $user !== null ? 'client_portal' : 'public_share_link'),
            stripe_customer_id: $stripe_customer_id,
            stripe_payment_method_id: $stripe_payment_method_id,
            save_for_future: $save_card && $user !== null && $payment_profile === null,
        );

        if (! $result['success']) {
            logger()->error("Failed to create invoice PaymentIntent for {$invoice->unique_id}", [
                'error' => $result['message'] ?? null,
            ]);

            return [
                'success'     => false,
                'message'     => 'We could not initialize the payment. Please try again.',
                'status_code' => 502,
            ];
        }

        return [
            'success'           => true,
            'client_secret'     => $result['client_secret'],
            'payment_intent_id' => $result['payment_intent_id'],
        ];
    }

    /**
     * Admin flow: charge the invoice owner's saved card off-session and mark
     * the invoice as paid.
     *
     * Returns ['success' => true]
     *      or ['success' => false, 'message' => '...', 'status_code' => int]
     */
    public function chargeSavedCard(Invoice $invoice, PaymentProfile $payment_profile, User $admin): array
    {
        if ($payment_profile->user_id !== $invoice->user_id) {
            return ['success' => false, 'message' => 'This card does not belong to the invoice client.', 'status_code' => 422];
        }

        if ($this->isPaymentProfileExpired($payment_profile)) {
            return ['success' => false, 'message' => 'This card has expired. Please choose another card.', 'status_code' => 422];
        }

        // Prevents double charges when the action is triggered twice in a row.
        $charge_lock = Cache::lock("invoice-card-charge:{$invoice->id}", 60);

        if (! $charge_lock->get()) {
            return ['success' => false, 'message' => 'A charge for this invoice is already in progress.', 'status_code' => 409];
        }

        try {
            $invoice->refresh();

            $payable_error = $this->validateCardPayable($invoice);

            if ($payable_error !== null) {
                return ['success' => false, ...$payable_error];
            }

            $client = $invoice->user;

            if (! $client) {
                return ['success' => false, 'message' => 'The invoice client could not be found.', 'status_code' => 422];
            }

            $customer_result = $this->resolvePaymentProfileCustomer($payment_profile, $client);

            if (! $customer_result['success']) {
                return ['success' => false, 'message' => $customer_result['message'], 'status_code' => 422];
            }

            $charge_result = $this->stripe_service->chargeSavedCardOffSession(
                amount_cents: $this->toCents((float) $invoice->total_amount),
                stripe_customer_id: $customer_result['customer_id'],
                stripe_payment_method_id: $payment_profile->stripe_payment_method_id,
                metadata: $this->buildMetadata($invoice, 'admin_card_on_file') + [
                    'charged_by_admin_id' => (string) $admin->id,
                ],
            );

            if (! $charge_result['success']) {
                logger()->warning("Admin card-on-file charge declined for invoice {$invoice->unique_id}", [
                    'payment_profile_id' => $payment_profile->id,
                    'error_code'         => $charge_result['error_code'] ?? null,
                ]);

                return ['success' => false, 'message' => $charge_result['message'], 'status_code' => 402];
            }

            $payment_intent_id = $charge_result['payment_intent_id'];
            $card_label        = $this->formatCardLabel($payment_profile);
            $admin_name        = $admin->full_name ?? $admin->email;

            return $this->finalizeCardPayment(
                invoice: $invoice,
                payment_intent_id: $payment_intent_id,
                history: [
                    'event'          => 'invoice_paid',
                    'description'    => "Card on file ({$card_label}) charged by admin. PaymentIntent: {$payment_intent_id}",
                    'actor_id'       => $admin->id,
                    'actor_name'     => $admin_name,
                    'actor_initials' => $this->buildInitials($admin_name),
                    'actor_type'     => 'admin',
                ],
                transaction_description: "Invoice {$invoice->invoice_number} paid by charging card on file ({$card_label}).",
            );
        } finally {
            $charge_lock->release();
        }
    }

    /**
     * After a deferred invoice is paid, transition all associated
     * payment_pending orders so work can begin. An order whose intake details
     * are still missing lands in `pending_details` instead of `new_request`;
     * a complete Link Building order also has its turnaround clock started.
     */
    public function transitionPaymentPendingOrders(Invoice $invoice, ?string $payment_intent_id): void
    {
        if (! $invoice->session_id && ! $invoice->order_id) {
            return;
        }

        foreach (self::ORDER_MODELS as $model) {
            $builder = $invoice->session_id
                ? $model::where('session_id', $invoice->session_id)
                : $model::where('id', $invoice->order_id);

            foreach ($builder->where('status', 'payment_pending')->get() as $order) {
                $order->payment_intent_id = $payment_intent_id;
                $order->save();

                // An invoice created from a "Skip for now" Pay Later checkout
                // carries details_deferred=true, which forces pending_details.
                $this->order_details_service->applyPaidStatus($order, (bool) $invoice->details_deferred);
            }
        }
    }

    /**
     * Saved cards for a user, formatted for the pay page / admin charge dialog.
     */
    public function listPaymentProfiles(User $user): array
    {
        return PaymentProfile::where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PaymentProfile $profile) => [
                'id'              => $profile->id,
                'card_brand'      => $profile->card_brand,
                'last_four'       => $profile->last_four,
                'expiry_month'    => $profile->expiry_month,
                'expiry_year'     => $profile->expiry_year,
                'cardholder_name' => $profile->cardholder_name,
                'is_default'      => $profile->is_default,
                'is_expired'      => $this->isPaymentProfileExpired($profile),
            ])
            ->values()
            ->all();
    }

    public function isPaymentProfileExpired(PaymentProfile $profile): bool
    {
        $expiry_month = (int) $profile->expiry_month;
        $expiry_year  = (int) $profile->expiry_year;

        if ($expiry_month < 1 || $expiry_year < 1) {
            return false;
        }

        return now()->startOfMonth()->gt(now()->setDate($expiry_year, $expiry_month, 1)->startOfMonth());
    }

    /**
     * Records the payment (invoice, history, transaction) under a row lock,
     * then captures the Stripe authorization. If the DB write fails, the
     * authorization is voided so the customer is never charged without a
     * recorded payment.
     */
    private function finalizeCardPayment(
        Invoice $invoice,
        string $payment_intent_id,
        array $history,
        string $transaction_description
    ): array {
        $already_paid = false;

        try {
            DB::transaction(function () use ($invoice, $payment_intent_id, $history, $transaction_description, &$already_paid) {
                $locked = Invoice::where('id', $invoice->id)->lockForUpdate()->first();

                if (! in_array($locked->status, self::PAYABLE_STATUSES, true)) {
                    $already_paid = true;
                    return;
                }

                $locked->update([
                    'status'            => 'paid',
                    'date_paid'         => now(),
                    'payment_method'    => 'Credit Card',
                    'payment_intent_id' => $payment_intent_id,
                ]);

                $locked->history()->create($history);

                Transaction::create([
                    'user_id'           => $locked->user_id,
                    'type'              => 'purchase',
                    'status'            => 'success',
                    'amount'            => $locked->total_amount,
                    'payment_method'    => 'credit_card',
                    'payment_intent_id' => $payment_intent_id,
                    'invoice_id'        => (string) $locked->id,
                    'description'       => $transaction_description,
                ]);
            });
        } catch (\Exception $e) {
            logger()->error("Failed to record card payment for invoice {$invoice->unique_id} — voiding Stripe authorization", [
                'payment_intent_id' => $payment_intent_id,
                'error'             => $e->getMessage(),
            ]);

            $this->stripe_service->cancelPaymentIntent($payment_intent_id);

            return [
                'success'     => false,
                'message'     => 'The payment could not be recorded. The card authorization has been voided and the card was not charged.',
                'status_code' => 500,
            ];
        }

        if ($already_paid) {
            // Paid concurrently through another flow — release this authorization.
            $this->stripe_service->cancelPaymentIntent($payment_intent_id);

            return ['success' => false, 'message' => 'This invoice has already been paid.', 'status_code' => 409];
        }

        $capture_result = $this->stripe_service->capturePaymentIntent($payment_intent_id);

        if (! $capture_result['success']) {
            logger()->critical("Stripe capture FAILED after invoice DB commit — invoice paid but payment not collected for {$invoice->unique_id}", [
                'payment_intent_id' => $payment_intent_id,
                'capture_error'     => $capture_result['message'] ?? 'Unknown error',
            ]);
        }

        $invoice->refresh();

        $this->transitionPaymentPendingOrders($invoice, $payment_intent_id);
        $this->dispatchPaymentNotifications($invoice);

        return ['success' => true];
    }

    /**
     * Non-critical notifications: failures are logged, never rolled back.
     */
    private function dispatchPaymentNotifications(Invoice $invoice): void
    {
        $client = $invoice->user;

        try {
            if ($client && $client->email) {
                Mail::to($client->email)->queue(new PaymentSuccessfulEmail($client, $invoice));
            }
        } catch (\Exception $e) {
            logger()->warning("Failed to queue client payment confirmation email for invoice {$invoice->unique_id}", [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            SendAdminInvoicePaidNotificationJob::dispatch($invoice->id);

            $payer_name = $client?->full_name ?? $client?->email ?? 'A client';

            $this->dispatchAdminPaymentCompletedEvent($invoice, $payer_name, (float) $invoice->total_amount);
        } catch (\Exception $e) {
            logger()->warning("Failed to dispatch admin payment notifications for invoice {$invoice->unique_id}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolves the Stripe Customer the saved card is attached to, attaching it
     * to the user's customer when it is not attached to any.
     *
     * Returns ['success' => true, 'customer_id' => '...'] or ['success' => false, 'message' => '...']
     */
    private function resolvePaymentProfileCustomer(PaymentProfile $payment_profile, User $user): array
    {
        $payment_method_result = $this->stripe_service->retrievePaymentMethod($payment_profile->stripe_payment_method_id);

        if (! $payment_method_result['success']) {
            return ['success' => false, 'message' => 'This saved card is no longer valid. Please use another card.'];
        }

        $customer_result = $this->stripe_service->findOrCreateCustomer($user);

        if (! $customer_result['success']) {
            return ['success' => false, 'message' => 'Unable to load the billing account for this card.'];
        }

        $payment_method_customer_id = $payment_method_result['customer_id'] ?? null;

        if ($payment_method_customer_id === null) {
            $attach_result = $this->stripe_service->attachPaymentMethod(
                $payment_profile->stripe_payment_method_id,
                $customer_result['customer_id']
            );

            if (! $attach_result['success']) {
                return ['success' => false, 'message' => 'This saved card could not be used. Please use another card.'];
            }

            return ['success' => true, 'customer_id' => $customer_result['customer_id']];
        }

        if ($payment_method_customer_id !== $customer_result['customer_id']) {
            return ['success' => false, 'message' => 'This saved card is associated with a different account.'];
        }

        return ['success' => true, 'customer_id' => $payment_method_customer_id];
    }

    private function buildMetadata(Invoice $invoice, string $payment_source): array
    {
        return [
            'invoice_unique_id' => $invoice->unique_id,
            'invoice_number'    => (string) $invoice->invoice_number,
            'invoice_user_id'   => (string) $invoice->user_id,
            'payment_source'    => $payment_source,
        ];
    }

    private function formatCardLabel(PaymentProfile $payment_profile): string
    {
        return ucfirst((string) $payment_profile->card_brand) . ' ending in ' . $payment_profile->last_four;
    }

    private function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function buildInitials(string $name): string
    {
        $parts = array_filter(explode(' ', trim($name)));

        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
        }

        return strtoupper(mb_substr($name, 0, 2));
    }
}
