<?php

namespace App\Services;

use App\Models\AutopaySetting;
use App\Models\Invoice;
use App\Models\InvoiceChargeAttempt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Decides which invoices autopay may charge and runs those charges through
 * SavedCardChargeService.
 *
 * An invoice is charged automatically only when ALL of the following hold:
 *  - the client has autopay enabled with a selected saved card,
 *  - the client account is active,
 *  - the invoice is unpaid/overdue, in USD and at least $0.50,
 *  - the invoice was created after autopay was (last) enabled — invoices that
 *    already existed when the client opted in are never charged retroactively,
 *  - the invoice is due (date_due, or date_issued when no due date is set),
 *  - the amount does not exceed the client's optional autopay limit,
 *  - there is no charge in flight / awaiting review, and the retry budget for
 *    the current card is not exhausted.
 *
 * The runner is driven by the Laravel scheduler (cron), not by queue workers,
 * so it keeps working even when supervisor is down. Missed runs are caught up
 * on the next run because selection is state-based (due and unpaid), not
 * time-window based.
 */
class AutopayService
{
    public const LAST_RUN_CACHE_KEY = 'autopay:last_run';

    public function __construct(private SavedCardChargeService $charge_service) {}

    /**
     * @return array{reconciled: int, charged: int, failed: int, skipped: int, pending: int, candidates: array}
     */
    public function runDueCharges(bool $dry_run = false, ?string $invoice_unique_id = null): array
    {
        $summary = [
            'reconciled' => 0,
            'charged'    => 0,
            'failed'     => 0,
            'skipped'    => 0,
            'pending'    => 0,
            'candidates' => [],
        ];

        if (! $dry_run) {
            $summary['reconciled'] = $this->charge_service->reconcileStaleAttempts();
        }

        $settings = AutopaySetting::with(['user', 'paymentProfile'])
            ->where('is_enabled', true)
            ->whereNotNull('payment_profile_id')
            ->get();

        foreach ($settings as $setting) {
            if (! $setting->user?->is_active || ! $setting->paymentProfile) {
                continue;
            }

            foreach ($this->dueInvoicesFor($setting, $invoice_unique_id) as $invoice) {
                $decision = $this->decide($invoice, $setting);

                if ($decision === null) {
                    continue;
                }

                $summary['candidates'][] = [
                    'invoice_number' => $invoice->invoice_number,
                    'client'         => $setting->user->email,
                    'amount'         => (float) $invoice->total_amount,
                    'action'         => $decision,
                ];

                if ($dry_run) {
                    continue;
                }

                if ($decision === 'skip_limit') {
                    $this->recordLimitSkip($invoice, $setting);
                    $summary['skipped']++;
                    continue;
                }

                $result = $this->charge_service->chargeInvoice(
                    $invoice,
                    $setting->paymentProfile,
                    InvoiceChargeAttempt::SOURCE_AUTOPAY,
                );

                match ($result['status']) {
                    InvoiceChargeAttempt::STATUS_SUCCEEDED  => $summary['charged']++,
                    InvoiceChargeAttempt::STATUS_PROCESSING => $summary['pending']++,
                    default                                 => $summary['failed']++,
                };
            }
        }

        if (! $dry_run) {
            Cache::forever(self::LAST_RUN_CACHE_KEY, [
                'ran_at'  => now()->toIso8601String(),
                'summary' => collect($summary)->except('candidates')->all(),
            ]);
        }

        return $summary;
    }

    /**
     * Next date autopay would charge this invoice, or null when autopay does not apply.
     */
    public function describeInvoiceSchedule(Invoice $invoice, ?AutopaySetting $setting): ?array
    {
        if (! $setting?->is_enabled || ! $setting->paymentProfile) {
            return null;
        }

        if ($invoice->created_at && $setting->enabled_at && $invoice->created_at->lt($setting->enabled_at)) {
            return null;
        }

        $charge_date = $invoice->date_due ?? $invoice->date_issued;

        return [
            'charge_date' => $charge_date?->toIso8601String(),
            'card_label'  => $this->charge_service->buildCardLabel(
                $setting->paymentProfile->card_brand,
                $setting->paymentProfile->last_four,
            ),
            'exceeds_limit' => $setting->max_amount !== null && (float) $invoice->total_amount > $setting->max_amount,
        ];
    }

    private function dueInvoicesFor(AutopaySetting $setting, ?string $invoice_unique_id): Collection
    {
        $now = now();

        return Invoice::with('user')
            ->where('user_id', $setting->user_id)
            ->whereIn('status', ['unpaid', 'overdue'])
            ->where('currency_type', 'usd')
            ->where('total_amount', '>=', 0.5)
            ->where('created_at', '>=', $setting->enabled_at)
            ->where(function ($query) use ($now) {
                $query->where('date_due', '<=', $now)
                    ->orWhere(fn ($q) => $q->whereNull('date_due')->where('date_issued', '<=', $now));
            })
            ->when($invoice_unique_id, fn ($query) => $query->where('unique_id', $invoice_unique_id))
            ->orderBy('date_due')
            ->get();
    }

    /**
     * Returns 'charge', 'skip_limit', or null when the invoice must be left alone.
     */
    private function decide(Invoice $invoice, AutopaySetting $setting): ?string
    {
        $attempts = InvoiceChargeAttempt::where('invoice_id', $invoice->id)->get();

        $is_blocked = $attempts->contains(fn ($attempt) => in_array($attempt->status, [
            InvoiceChargeAttempt::STATUS_PROCESSING,
            InvoiceChargeAttempt::STATUS_REQUIRES_REVIEW,
            InvoiceChargeAttempt::STATUS_SUCCEEDED,
        ], true));

        if ($is_blocked) {
            return null;
        }

        if ($setting->max_amount !== null && (float) $invoice->total_amount > $setting->max_amount) {
            $already_notified = $attempts->contains(
                fn ($attempt) => $attempt->status === InvoiceChargeAttempt::STATUS_SKIPPED
                    && (float) $attempt->amount === (float) $invoice->total_amount
            );

            return $already_notified ? null : 'skip_limit';
        }

        // Retry budget is per card: when the client switches to a new card the
        // invoice gets a fresh set of attempts.
        $failed_with_current_card = $attempts
            ->where('source', InvoiceChargeAttempt::SOURCE_AUTOPAY)
            ->where('status', InvoiceChargeAttempt::STATUS_FAILED)
            ->where('payment_profile_id', $setting->payment_profile_id)
            ->sortByDesc('created_at');

        if ($failed_with_current_card->count() >= SavedCardChargeService::MAX_AUTOPAY_ATTEMPTS) {
            return null;
        }

        $latest_failure = $failed_with_current_card->first();

        if ($latest_failure) {
            if (! $latest_failure->is_retryable || ! $latest_failure->next_retry_at) {
                return null;
            }

            if ($latest_failure->next_retry_at->isFuture()) {
                return null;
            }
        }

        return 'charge';
    }

    private function recordLimitSkip(Invoice $invoice, AutopaySetting $setting): void
    {
        $limit  = '$' . number_format((float) $setting->max_amount, 2);
        $amount = '$' . number_format((float) $invoice->total_amount, 2);

        $attempt = InvoiceChargeAttempt::create([
            'invoice_id'         => $invoice->id,
            'user_id'            => $invoice->user_id,
            'payment_profile_id' => $setting->payment_profile_id,
            'source'             => InvoiceChargeAttempt::SOURCE_AUTOPAY,
            'attempt_number'     => 0,
            'status'             => InvoiceChargeAttempt::STATUS_SKIPPED,
            'amount'             => $invoice->total_amount,
            'card_brand'         => $setting->paymentProfile?->card_brand,
            'card_last_four'     => $setting->paymentProfile?->last_four,
            'idempotency_key'    => "invoice-charge:{$invoice->id}:autopay:skipped:" . Str::uuid(),
            'failure_code'       => 'exceeds_autopay_limit',
            'failure_message'    => "The invoice total ({$amount}) exceeds the autopay limit of {$limit} per invoice, so it was not charged automatically.",
            'initiated_by_name'  => 'System (Autopay)',
            'completed_at'       => now(),
        ]);

        $invoice->history()->create([
            'event'          => 'autopay skipped',
            'description'    => $attempt->failure_message,
            'actor_name'     => 'System (Autopay)',
            'actor_initials' => 'AP',
            'actor_type'     => 'system',
        ]);

        $this->charge_service->sendAutopayFailureNotifications($attempt);
    }
}
