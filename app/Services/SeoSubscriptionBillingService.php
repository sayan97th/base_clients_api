<?php

namespace App\Services;

use App\Jobs\SendAdminSeoSubscriptionBillingNotificationJob;
use App\Jobs\SendEmailJob;
use App\Mail\AdminSeoSubscriptionBillingNotification;
use App\Mail\SeoSubscriptionBillingEmail;
use App\Models\Invoice;
use App\Models\InvoiceHistory;
use App\Models\PaymentProfile;
use App\Models\SeoPackage;
use App\Models\SeoPackageSubscription;
use App\Models\SeoSubscriptionCharge;
use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Recurring billing for admin managed SEO subscriptions.
 *
 * How a renewal works:
 *   1. Each billing period gets exactly one SeoSubscriptionCharge row
 *      (unique per subscription and period start), so a month can never be
 *      billed twice.
 *   2. The period gets a regular portal invoice, "December - SEO Services",
 *      the same kind of invoice the team used to duplicate by hand. The
 *      client can always see and pay it from their portal.
 *   3. The client's card on file is charged off-session through Stripe with
 *      an idempotency key, reusing the invoice card payment flow (authorize,
 *      record, then capture).
 *   4. A declined renewal is retried on a dunning schedule. Declines that a
 *      retry cannot fix (expired card, authentication required, no card on
 *      file) are not retried, so the client's card is not hit pointlessly.
 *
 * The client gets a payment confirmation, a payment failed notice (first
 * and final failure only) and an optional reminder a few days before each
 * renewal. The admin recipients in Email Notification Settings get their own
 * email for every collected payment and every failed renewal, plus an in-app
 * notification when a renewal fails.
 */
class SeoSubscriptionBillingService
{
    /** Days to wait before each automatic retry of a declined renewal. */
    public const RETRY_SCHEDULE_DAYS = [3, 5, 7];

    /** Declines a retry cannot fix, the client has to act first. */
    public const NON_RETRYABLE_ERROR_CODES = [
        'authentication_required',
        'expired_card',
        'lost_card',
        'stolen_card',
        'pickup_card',
        'fraudulent',
        'incorrect_number',
        'invalid_account',
        'card_not_supported',
        'restricted_card',
        'no_payment_method',
        'invalid_payment_method',
        'card_owner_mismatch',
        'invoice_not_payable',
        'client_missing',
    ];

    public function __construct(
        protected InvoiceCardPaymentService $card_payment_service,
        protected InvoiceNumberGenerator $invoice_number_generator,
        protected NotificationService $notification_service,
    ) {}

    // ── Activation and settings ─────────────────────────────────────────────

    /**
     * Creates a subscription from validated admin input. The billing anchor
     * is the first billing date's day of the month.
     */
    public function activate(array $data): SeoPackageSubscription
    {
        $package      = isset($data['package_id']) ? SeoPackage::find($data['package_id']) : null;
        $pricing_type = $data['pricing_type'] ?? SeoPackageSubscription::PRICING_PACKAGE;

        $monthly_price = $pricing_type === SeoPackageSubscription::PRICING_CUSTOM
            ? round((float) $data['custom_price'], 2)
            : (float) ($package?->price_per_month ?? 0);

        $starts_at         = Carbon::parse($data['starts_at'])->startOfDay();
        $next_billing_date = isset($data['first_billing_date'])
            ? Carbon::parse($data['first_billing_date'])->startOfDay()
            : $starts_at->copy();

        return DB::transaction(fn () => SeoPackageSubscription::create([
            'user_id'                  => $data['user_id'],
            'seo_package_id'           => $package?->id,
            'plan_name'                => $this->resolvePlanName($data, $package),
            'pricing_type'             => $pricing_type,
            'monthly_price'            => $monthly_price,
            'invoice_item_label'       => $this->nullIfBlank($data['invoice_item_label'] ?? null),
            'invoice_item_description' => $this->nullIfBlank($data['invoice_item_description'] ?? null),
            'auto_renew'               => (bool) ($data['auto_renew'] ?? false),
            'payment_profile_id'       => $data['payment_profile_id'] ?? null,
            'billing_anchor_day'       => $next_billing_date->day,
            'next_billing_date'        => $next_billing_date->toDateString(),
            'reminder_days_before'     => array_key_exists('reminder_days_before', $data) ? $data['reminder_days_before'] : 3,
            'payment_status'           => SeoPackageSubscription::PAYMENT_STATUS_CURRENT,
            'status'                   => 'active',
            'starts_at'                => $starts_at->toDateString(),
            'ends_at'                  => $data['ends_at'] ?? null,
            'notes'                    => $data['notes'] ?? null,
        ]));
    }

    /**
     * Applies a partial update from the admin. Changing the price only
     * affects future periods; invoices already issued keep their amount.
     */
    public function updateSettings(SeoPackageSubscription $subscription, array $data): SeoPackageSubscription
    {
        $updates = [];

        if (array_key_exists('pricing_type', $data) || array_key_exists('package_id', $data) || array_key_exists('custom_price', $data)) {
            $pricing_type = $data['pricing_type'] ?? $subscription->pricing_type;
            $package_id   = array_key_exists('package_id', $data) ? $data['package_id'] : $subscription->seo_package_id;
            $package      = $package_id ? SeoPackage::find($package_id) : null;

            $updates['pricing_type']   = $pricing_type;
            $updates['seo_package_id'] = $package?->id;
            $updates['monthly_price']  = $pricing_type === SeoPackageSubscription::PRICING_CUSTOM
                ? round((float) ($data['custom_price'] ?? $subscription->monthly_price), 2)
                : (float) ($package?->price_per_month ?? $subscription->monthly_price);
            $updates['plan_name'] = $this->resolvePlanName(
                ['custom_plan_name' => $data['custom_plan_name'] ?? ($pricing_type === SeoPackageSubscription::PRICING_CUSTOM ? $subscription->plan_name : null)],
                $package
            );
        } elseif (array_key_exists('custom_plan_name', $data) && $subscription->isCustomPricing()) {
            $updates['plan_name'] = $this->resolvePlanName($data, $subscription->package);
        }

        foreach (['invoice_item_label', 'invoice_item_description', 'notes'] as $text_field) {
            if (array_key_exists($text_field, $data)) {
                $updates[$text_field] = $this->nullIfBlank($data[$text_field]);
            }
        }

        foreach (['payment_profile_id', 'reminder_days_before', 'ends_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if (array_key_exists('next_billing_date', $data) && $data['next_billing_date']) {
            $next_billing_date = Carbon::parse($data['next_billing_date'])->startOfDay();

            $updates['next_billing_date']      = $next_billing_date->toDateString();
            $updates['billing_anchor_day']     = $next_billing_date->day;
            $updates['last_reminder_sent_for'] = null;
        }

        if (array_key_exists('auto_renew', $data)) {
            $auto_renew = (bool) $data['auto_renew'];

            $updates['auto_renew']             = $auto_renew;
            $updates['auto_renew_disabled_at'] = $auto_renew ? null : ($subscription->auto_renew ? now() : $subscription->auto_renew_disabled_at);
        }

        $subscription->update($updates);

        return $subscription->refresh();
    }

    public function cancel(SeoPackageSubscription $subscription, ?string $notes = null): SeoPackageSubscription
    {
        $updates = [
            'status'                 => 'cancelled',
            'cancelled_at'           => now(),
            'auto_renew'             => false,
            'auto_renew_disabled_at' => $subscription->auto_renew ? now() : $subscription->auto_renew_disabled_at,
        ];

        if ($notes !== null && trim($notes) !== '') {
            $updates['notes'] = $notes;
        }

        $subscription->update($updates);

        // Stop pending retries so a cancelled client is never charged again.
        $subscription->charges()
            ->where('status', SeoSubscriptionCharge::STATUS_FAILED)
            ->whereNotNull('next_retry_at')
            ->update(['next_retry_at' => null]);

        return $subscription->refresh();
    }

    // ── Charging ────────────────────────────────────────────────────────────

    /**
     * Bills the subscription's current period (the one starting on
     * next_billing_date). Used by the scheduler for renewals and by admins
     * for "Charge now".
     *
     * Returns ['success' => bool, 'message' => string, 'status_code' => int,
     *          'charge' => ?SeoSubscriptionCharge]
     */
    public function chargeCurrentPeriod(
        SeoPackageSubscription $subscription,
        string $trigger,
        ?User $admin = null,
        ?PaymentProfile $payment_profile_override = null,
    ): array {
        $lock = Cache::lock("seo-subscription-billing:{$subscription->id}", 120);

        if (! $lock->get()) {
            return $this->result(false, 'A charge for this subscription is already in progress.', 409);
        }

        try {
            $subscription->refresh();

            if (! $subscription->isActive()) {
                return $this->result(false, 'Only active subscriptions can be charged.', 422);
            }

            if ($subscription->monthly_price < 0.5) {
                return $this->result(false, 'The monthly price is below the minimum card charge of $0.50.', 422);
            }

            $period_start = $this->ensureNextBillingDate($subscription);

            if ($subscription->ends_at && $period_start->gt($subscription->ends_at)) {
                return $this->result(false, 'This subscription ends before the next billing period, so there is nothing left to charge.', 422);
            }

            // One row per period (unique index): reused by every retry.
            $charge = $subscription->charges()
                ->whereDate('period_start', $period_start->toDateString())
                ->first()
                ?? SeoSubscriptionCharge::create([
                    'seo_package_subscription_id' => $subscription->id,
                    'user_id'                     => $subscription->user_id,
                    'period_start'                => $period_start->toDateString(),
                    'period_end'                  => $subscription->periodEndFor($period_start)->toDateString(),
                    'amount'                      => $subscription->monthly_price,
                    'status'                      => SeoSubscriptionCharge::STATUS_PENDING,
                    'trigger'                     => $trigger,
                ]);

            if ($charge->isSucceeded()) {
                // Defensive: the period was paid but the schedule did not move.
                $this->advanceBillingDate($subscription, $charge);

                return $this->result(false, "The {$charge->periodLabel()} period has already been paid.", 409, $charge);
            }

            $invoice = $this->resolvePeriodInvoice($subscription, $charge, $admin);

            // Paid some other way (portal, share link, marked paid by an admin).
            if ($invoice->status === 'paid') {
                $this->markChargePaidExternally($subscription, $charge, $invoice);

                return $this->result(true, "The {$charge->periodLabel()} invoice was already paid, no card was charged.", 200, $charge->refresh());
            }

            $payment_profile = $payment_profile_override ?? $this->resolvePaymentProfile($subscription);

            $charge->attempt_count   = $charge->attempt_count + 1;
            $charge->last_attempt_at = now();
            $charge->trigger         = $trigger;
            $charge->charged_by_id   = $admin?->id;

            if (! $payment_profile) {
                return $this->handleFailure($subscription, $charge, $invoice, [
                    'error_code' => 'no_payment_method',
                    'message'    => 'The client has no card on file. Ask them to add a card or pay the invoice from their portal.',
                ], $trigger);
            }

            $charge->payment_profile_id = $payment_profile->id;
            $charge->card_label         = $this->card_payment_service->formatCardLabel($payment_profile);
            $charge->save();

            $card_label  = $charge->card_label;
            $period_text = $charge->periodLabel();
            $actor_name  = $admin ? ($admin->full_name ?? $admin->email) : 'System';

            $charge_result = $this->card_payment_service->chargeInvoiceOffSession(
                invoice: $invoice,
                payment_profile: $payment_profile,
                payment_source: $trigger === SeoSubscriptionCharge::TRIGGER_AUTOMATIC ? 'seo_subscription_renewal' : 'seo_subscription_manual',
                history: fn (string $payment_intent_id) => [
                    'event'          => 'invoice_paid',
                    'description'    => ($trigger === SeoSubscriptionCharge::TRIGGER_AUTOMATIC
                        ? "SEO subscription auto renewal for {$period_text}. Card on file ({$card_label}) charged."
                        : "SEO subscription payment for {$period_text} charged by admin on card on file ({$card_label}).")
                        . " PaymentIntent: {$payment_intent_id}",
                    'actor_id'       => $admin?->id,
                    'actor_name'     => $actor_name,
                    'actor_initials' => $this->buildInitials($actor_name),
                    'actor_type'     => $admin ? 'admin' : 'system',
                ],
                transaction_description: "SEO subscription {$period_text}, invoice {$invoice->invoice_number} paid with card on file ({$card_label}).",
                extra_metadata: [
                    'seo_subscription_id'  => (string) $subscription->id,
                    'seo_subscription_charge_id' => (string) $charge->id,
                    'billing_period'       => $charge->period_start->toDateString(),
                    'charged_by_admin_id'  => $admin ? (string) $admin->id : '',
                ],
                send_client_receipt: false,
                send_admin_paid_email: false,
                idempotency_key: "seo-subscription-charge-{$charge->id}-attempt-{$charge->attempt_count}",
                stripe_description: "SEO subscription, {$period_text} ({$subscription->displayPlanName()})",
            );

            if (! $charge_result['success']) {
                if (($charge_result['error_code'] ?? null) === 'invoice_not_payable' && $invoice->fresh()?->status === 'paid') {
                    $this->markChargePaidExternally($subscription, $charge, $invoice->fresh());

                    return $this->result(true, "The {$period_text} invoice was already paid, no card was charged.", 200, $charge->refresh());
                }

                return $this->handleFailure($subscription, $charge, $invoice, $charge_result, $trigger);
            }

            $charge->fill([
                'status'            => SeoSubscriptionCharge::STATUS_SUCCEEDED,
                'payment_intent_id' => $charge_result['payment_intent_id'] ?? null,
                'paid_at'           => now(),
                'next_retry_at'     => null,
                'failure_code'      => null,
                'failure_message'   => null,
            ])->save();

            $this->advanceBillingDate($subscription, $charge);
            $this->sendClientEmail($subscription->refresh(), SeoSubscriptionBillingEmail::VARIANT_PAYMENT_SUCCEEDED, $charge, $invoice->fresh());
            $this->notifyAdminsByEmail($charge, AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_SUCCEEDED);

            return $this->result(true, "Charged \${$this->money($charge->amount)} for {$period_text}.", 200, $charge->refresh());
        } finally {
            $lock->release();
        }
    }

    /**
     * Renewals the scheduler should attempt now: active, auto renew on, the
     * billing date has arrived, and the period is not waiting for a retry.
     */
    public function processDueRenewals(): array
    {
        $summary = ['attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0];
        $today   = now()->toDateString();

        SeoPackageSubscription::query()
            ->where('status', 'active')
            ->where('auto_renew', true)
            ->whereNotNull('next_billing_date')
            ->whereDate('next_billing_date', '<=', $today)
            ->lazyById(100)
            ->each(function (SeoPackageSubscription $subscription) use (&$summary) {
                if ($subscription->ends_at && $subscription->next_billing_date->gt($subscription->ends_at)) {
                    $summary['skipped']++;
                    return;
                }

                $charge = $subscription->charges()
                    ->whereDate('period_start', $subscription->next_billing_date->toDateString())
                    ->first();

                if ($charge && $charge->status === SeoSubscriptionCharge::STATUS_FAILED) {
                    // The invoice may have been paid from the portal since.
                    if ($this->syncExternalPayment($subscription, $charge)) {
                        $summary['succeeded']++;
                        return;
                    }

                    if (! $charge->next_retry_at || $charge->next_retry_at->isFuture()) {
                        $summary['skipped']++;
                        return;
                    }
                }

                $summary['attempted']++;

                try {
                    $result = $this->chargeCurrentPeriod($subscription, SeoSubscriptionCharge::TRIGGER_AUTOMATIC);
                    $summary[$result['success'] ? 'succeeded' : 'failed']++;
                } catch (\Throwable $e) {
                    $summary['failed']++;
                    logger()->error("SEO subscription renewal crashed for subscription {$subscription->id}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        return $summary;
    }

    /**
     * Sends the "your subscription renews soon" email once per period, the
     * configured number of days before the renewal.
     */
    public function sendDueReminders(): int
    {
        $sent  = 0;
        $today = now()->startOfDay();

        SeoPackageSubscription::query()
            ->with(['user', 'paymentProfile'])
            ->where('status', 'active')
            ->where('auto_renew', true)
            ->where('payment_status', SeoPackageSubscription::PAYMENT_STATUS_CURRENT)
            ->whereNotNull('reminder_days_before')
            ->where('reminder_days_before', '>', 0)
            ->whereDate('next_billing_date', '>', $today->toDateString())
            ->whereDate('next_billing_date', '<=', $today->copy()->addDays(30)->toDateString())
            ->lazyById(100)
            ->each(function (SeoPackageSubscription $subscription) use ($today, &$sent) {
                $billing_date = $subscription->next_billing_date;

                if ($subscription->last_reminder_sent_for?->isSameDay($billing_date)) {
                    return;
                }

                if ($today->diffInDays($billing_date) > $subscription->reminder_days_before) {
                    return;
                }

                if ($subscription->ends_at && $billing_date->gt($subscription->ends_at)) {
                    return;
                }

                $this->sendClientEmail($subscription, SeoSubscriptionBillingEmail::VARIANT_RENEWAL_REMINDER);
                $subscription->update(['last_reminder_sent_for' => $billing_date->toDateString()]);
                $sent++;
            });

        return $sent;
    }

    /**
     * Subscriptions past their end date stop renewing and become expired.
     */
    public function expireEndedSubscriptions(): int
    {
        return SeoPackageSubscription::query()
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->whereDate('ends_at', '<', now()->toDateString())
            ->update([
                'status'     => 'expired',
                'auto_renew' => false,
                'updated_at' => now(),
            ]);
    }

    /**
     * Reconciles failed periods whose invoice has since been paid another way.
     */
    public function syncExternalPayments(SeoPackageSubscription $subscription): void
    {
        $subscription->charges()
            ->whereIn('status', [SeoSubscriptionCharge::STATUS_FAILED, SeoSubscriptionCharge::STATUS_PENDING])
            ->whereNotNull('invoice_id')
            ->get()
            ->each(fn (SeoSubscriptionCharge $charge) => $this->syncExternalPayment($subscription, $charge));
    }

    public function resolvePaymentProfile(SeoPackageSubscription $subscription): ?PaymentProfile
    {
        $selected = $subscription->paymentProfile;

        if ($selected && $selected->user_id === $subscription->user_id && ! $this->card_payment_service->isPaymentProfileExpired($selected)) {
            return $selected;
        }

        return PaymentProfile::where('user_id', $subscription->user_id)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get()
            ->first(fn (PaymentProfile $profile) => ! $this->card_payment_service->isPaymentProfileExpired($profile));
    }

    /**
     * Next charges for the admin overview: a short, human readable schedule.
     */
    public function upcomingSchedule(SeoPackageSubscription $subscription, int $count = 3): array
    {
        if (! $subscription->isActive() || ! $subscription->next_billing_date) {
            return [];
        }

        $schedule = [];
        $date     = $subscription->next_billing_date->copy();

        for ($i = 0; $i < $count; $i++) {
            if ($subscription->ends_at && $date->gt($subscription->ends_at)) {
                break;
            }

            $schedule[] = [
                'date'         => $date->toDateString(),
                'period_label' => $date->format('F Y'),
                'amount'       => $subscription->monthly_price,
            ];

            $date = SeoPackageSubscription::advanceByOneMonth($date, $subscription->anchorDay());
        }

        return $schedule;
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function handleFailure(
        SeoPackageSubscription $subscription,
        SeoSubscriptionCharge $charge,
        Invoice $invoice,
        array $charge_result,
        string $trigger,
    ): array {
        $error_code   = $charge_result['error_code'] ?? 'card_declined';
        $message      = $charge_result['message'] ?? 'The card could not be charged.';
        $is_final     = in_array($error_code, self::NON_RETRYABLE_ERROR_CODES, true)
            || $charge->attempt_count > count(self::RETRY_SCHEDULE_DAYS);
        $retry_in     = self::RETRY_SCHEDULE_DAYS[$charge->attempt_count - 1] ?? null;
        $is_first     = $charge->attempt_count <= 1;

        $charge->fill([
            'status'            => SeoSubscriptionCharge::STATUS_FAILED,
            'failure_code'      => $error_code,
            'failure_message'   => $message,
            'payment_intent_id' => $charge_result['payment_intent_id'] ?? $charge->payment_intent_id,
            // Only auto renewing subscriptions are retried automatically.
            'next_retry_at'     => ! $is_final && $retry_in !== null && $subscription->auto_renew ? now()->addDays($retry_in) : null,
        ])->save();

        $subscription->update(['payment_status' => SeoPackageSubscription::PAYMENT_STATUS_PAST_DUE]);

        InvoiceHistory::create([
            'invoice_id'     => $invoice->id,
            'event'          => 'payment failed',
            'description'    => "SEO subscription charge for {$charge->periodLabel()} failed (attempt {$charge->attempt_count}): {$message}",
            'actor_id'       => $charge->charged_by_id,
            'actor_name'     => 'System',
            'actor_initials' => 'SY',
            'actor_type'     => 'system',
        ]);

        // Automatic renewals tell the client on the first and the final
        // failure only. When an admin charges manually they see the result
        // right away and decide whether to contact the client.
        if ($trigger === SeoSubscriptionCharge::TRIGGER_AUTOMATIC) {
            if ($is_first || $charge->next_retry_at === null) {
                $this->sendClientEmail($subscription->refresh(), SeoSubscriptionBillingEmail::VARIANT_PAYMENT_FAILED, $charge, $invoice);
            }

            $this->notifyAdminsOfFailure($subscription, $charge);
            $this->notifyAdminsByEmail($charge, AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_FAILED);
        }

        return $this->result(false, $message, 402, $charge->refresh());
    }

    private function markChargePaidExternally(SeoPackageSubscription $subscription, SeoSubscriptionCharge $charge, Invoice $invoice): void
    {
        $charge->fill([
            'status'            => SeoSubscriptionCharge::STATUS_SUCCEEDED,
            'paid_at'           => $invoice->date_paid ?? now(),
            'payment_intent_id' => $invoice->payment_intent_id ?? $charge->payment_intent_id,
            'next_retry_at'     => null,
            'failure_code'      => null,
            'failure_message'   => null,
            'trigger'           => $charge->attempt_count > 0 ? $charge->trigger : SeoSubscriptionCharge::TRIGGER_EXTERNAL,
        ])->save();

        $this->advanceBillingDate($subscription, $charge);
    }

    private function syncExternalPayment(SeoPackageSubscription $subscription, SeoSubscriptionCharge $charge): bool
    {
        $invoice = $charge->invoice_id ? Invoice::find($charge->invoice_id) : null;

        if (! $invoice || $invoice->status !== 'paid') {
            return false;
        }

        $this->markChargePaidExternally($subscription, $charge, $invoice);

        return true;
    }

    /**
     * Moves the schedule to the next period once this one is paid. Only
     * moves forward, and only when the paid period is the current one.
     */
    private function advanceBillingDate(SeoPackageSubscription $subscription, SeoSubscriptionCharge $charge): void
    {
        $subscription->refresh();

        $updates = ['payment_status' => SeoPackageSubscription::PAYMENT_STATUS_CURRENT];

        if (! $subscription->next_billing_date || $subscription->next_billing_date->lte($charge->period_start)) {
            $updates['next_billing_date'] = SeoPackageSubscription::advanceByOneMonth(
                $charge->period_start,
                $subscription->anchorDay()
            )->toDateString();
        }

        // Another period may still be unpaid if the schedule was moved by hand.
        $has_other_failed = $subscription->charges()
            ->where('id', '!=', $charge->id)
            ->where('status', SeoSubscriptionCharge::STATUS_FAILED)
            ->exists();

        if ($has_other_failed) {
            $updates['payment_status'] = SeoPackageSubscription::PAYMENT_STATUS_PAST_DUE;
        }

        $subscription->update($updates);
    }

    private function ensureNextBillingDate(SeoPackageSubscription $subscription): Carbon
    {
        if ($subscription->next_billing_date) {
            return $subscription->next_billing_date->copy()->startOfDay();
        }

        $today = now()->startOfDay();

        $subscription->update([
            'next_billing_date'  => $today->toDateString(),
            'billing_anchor_day' => $subscription->billing_anchor_day ?: $today->day,
        ]);

        return $today;
    }

    /**
     * Reuses the period's invoice when it is still payable, otherwise issues
     * a new one, built exactly like an invoice created by hand in the admin.
     */
    private function resolvePeriodInvoice(SeoPackageSubscription $subscription, SeoSubscriptionCharge $charge, ?User $admin): Invoice
    {
        $existing = $charge->invoice_id ? Invoice::find($charge->invoice_id) : null;

        if ($existing && in_array($existing->status, ['unpaid', 'overdue', 'paid'], true)) {
            return $existing;
        }

        $client      = $subscription->user()->with(['billingAddress', 'organization'])->first();
        $amount      = round((float) $charge->amount, 2);
        $item_name   = $charge->period_start->format('F') . ' - ' . $subscription->invoiceItemLabel();
        $description = $subscription->invoice_item_description
            ?: "{$subscription->displayPlanName()}, service period {$charge->period_start->format('M j, Y')} to {$charge->period_end->format('M j, Y')}.";

        $invoice = $this->invoice_number_generator->transact(function () use ($client, $subscription, $charge, $amount, $item_name, $description, $admin) {
            $invoice = Invoice::create([
                'unique_id'       => strtoupper(bin2hex(random_bytes(4))),
                'invoice_number'  => $this->invoice_number_generator->next(),
                'user_id'         => $client->id,
                'status'          => 'unpaid',
                'payment_method'  => 'Credit Card',
                'currency_type'   => 'usd',
                'subtotal_amount' => $amount,
                'discount_amount' => 0.0,
                'discount_type'   => null,
                'total_amount'    => $amount,
                'credit_amount'   => 0.0,
                'notes'           => "Monthly payment for your {$subscription->displayPlanName()} subscription ({$charge->periodLabel()}).",
                'date_issued'     => now(),
                'date_due'        => $charge->period_start->copy()->endOfDay(),
                'date_paid'       => null,
            ]);

            $invoice->lineItems()->create([
                'item_name'        => $item_name,
                'description'      => $description,
                'price'            => $amount,
                'quantity'         => 1,
                'discount_percent' => 0,
                'item_total'       => $amount,
            ]);

            $billing = $client->billingAddress;

            $invoice->billedTo()->create([
                'company_name'        => $billing?->company ?? $client->organization?->name,
                'company_description' => $client->job_title ?? null,
                'address_line_1'      => $billing?->address ?? null,
                'address_line_2'      => $billing?->address_line_2 ?? null,
                'state'               => $billing?->state_province ?? null,
                'country'             => $billing?->country ?? null,
            ]);

            $actor_name = $admin ? ($admin->full_name ?? $admin->email) : 'System';

            InvoiceHistory::create([
                'invoice_id'     => $invoice->id,
                'event'          => 'invoice created',
                'description'    => "Invoice generated for the SEO subscription period {$charge->periodLabel()}.",
                'actor_id'       => $admin?->id,
                'actor_name'     => $actor_name,
                'actor_initials' => $this->buildInitials($actor_name),
                'actor_type'     => $admin ? 'admin' : 'system',
            ]);

            return $invoice;
        });

        $charge->update(['invoice_id' => $invoice->id]);

        return $invoice;
    }

    private function sendClientEmail(
        SeoPackageSubscription $subscription,
        string $variant,
        ?SeoSubscriptionCharge $charge = null,
        ?Invoice $invoice = null,
    ): void {
        try {
            $subscription->loadMissing(['user', 'paymentProfile']);
            $client = $subscription->user;

            if (! $client || ! $client->email) {
                return;
            }

            $payment_profile = $charge?->payment_profile_id
                ? PaymentProfile::find($charge->payment_profile_id)
                : $this->resolvePaymentProfile($subscription);

            $card_label = $charge?->card_label
                ?? ($payment_profile ? $this->card_payment_service->formatCardLabel($payment_profile) : '');

            $billing_date = $charge?->period_start ?? $subscription->next_billing_date ?? now();
            $period_end   = $charge?->period_end ?? $subscription->periodEndFor($billing_date);

            SendEmailJob::dispatch(
                new SeoSubscriptionBillingEmail(
                    variant:           $variant,
                    client_first_name: (string) ($client->first_name ?: ''),
                    client_email:      $client->email,
                    plan_name:         $subscription->displayPlanName(),
                    amount:            '$' . $this->money($charge?->amount ?? $subscription->monthly_price),
                    period_label:      $billing_date->format('F Y'),
                    period_range:      $billing_date->format('M j, Y') . ' to ' . $period_end->format('M j, Y'),
                    billing_date:      $billing_date->format('F j, Y'),
                    next_billing_date: $subscription->next_billing_date?->format('F j, Y') ?? '',
                    card_brand:        strtolower((string) ($payment_profile?->card_brand ?? '')),
                    card_label:        $card_label,
                    invoice_number:    $invoice ? (string) $invoice->invoice_number : '',
                    invoice_url:       $invoice ? FrontendUrl::to('/invoices/' . $invoice->unique_id) : '',
                    failure_reason:    $variant === SeoSubscriptionBillingEmail::VARIANT_PAYMENT_FAILED ? $this->clientFacingFailureReason($charge?->failure_code) : '',
                    next_retry_date:   $charge?->next_retry_at?->format('F j, Y') ?? '',
                    paid_at:           $charge?->paid_at?->format('F j, Y') ?? '',
                    billing_url:       FrontendUrl::to('/billing'),
                    support_url:       FrontendUrl::to('/support'),
                ),
                $client->email,
            );
        } catch (\Throwable $e) {
            logger()->warning("Failed to queue SEO subscription {$variant} email for subscription {$subscription->id}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyAdminsOfFailure(SeoPackageSubscription $subscription, SeoSubscriptionCharge $charge): void
    {
        try {
            $subscription->loadMissing('user');
            $recipients  = EmailNotificationSettingService::resolveAdminRecipients();
            $admin_users = User::whereIn('email', array_column($recipients, 'email'))
                ->where('is_active', true)
                ->get();

            $client_name = $subscription->user?->full_name ?: ($subscription->user?->email ?? 'A client');
            $retry_text  = $charge->next_retry_at ? " Next retry on {$charge->next_retry_at->format('M j')}." : ' No more automatic retries.';

            foreach ($admin_users as $admin_user) {
                $this->notification_service->createNotification(
                    user: $admin_user,
                    type: 'invoice',
                    message: "SEO subscription payment failed for {$client_name} ({$charge->periodLabel()}, \${$this->money($charge->amount)}).{$retry_text}",
                    extra: ['link' => '/admin/seo-packages-subscriptions/' . $subscription->id],
                );
            }
        } catch (\Throwable $e) {
            logger()->warning("Failed to notify admins about SEO subscription charge {$charge->id}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Admin recipients from Email Notification Settings get their own email
     * for subscription payments (the generic "invoice paid" one is skipped).
     */
    private function notifyAdminsByEmail(SeoSubscriptionCharge $charge, string $variant): void
    {
        try {
            SendAdminSeoSubscriptionBillingNotificationJob::dispatch($charge->id, $variant);
        } catch (\Throwable $e) {
            logger()->warning("Failed to queue the admin SEO subscription {$variant} email for charge {$charge->id}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function clientFacingFailureReason(?string $error_code): string
    {
        return match ($error_code) {
            'no_payment_method'                                     => 'There is no card on file for your account.',
            'expired_card'                                          => 'The card on file has expired.',
            'authentication_required'                               => 'Your bank asked for extra verification for this payment.',
            'insufficient_funds'                                    => 'Your bank declined the payment due to insufficient funds.',
            'lost_card', 'stolen_card', 'pickup_card', 'restricted_card' => 'Your bank declined the card on file.',
            'incorrect_number', 'invalid_payment_method', 'invalid_account' => 'The card on file is no longer valid.',
            default                                                 => 'Your bank declined the payment.',
        };
    }

    private function resolvePlanName(array $data, ?SeoPackage $package): string
    {
        $custom_name = trim((string) ($data['custom_plan_name'] ?? ''));

        if ($custom_name !== '') {
            return $custom_name;
        }

        return $package?->name ?? 'Custom SEO Package';
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2);
    }

    private function result(bool $success, string $message, int $status_code, ?SeoSubscriptionCharge $charge = null): array
    {
        return compact('success', 'message', 'status_code', 'charge');
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
