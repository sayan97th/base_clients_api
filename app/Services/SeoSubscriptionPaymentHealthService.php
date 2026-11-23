<?php

namespace App\Services;

use App\Models\PaymentProfile;
use App\Models\SeoPackageSubscription;
use App\Models\SeoSubscriptionCharge;
use Illuminate\Support\Collection;

/**
 * Answers one question for admins and clients: "is this subscription being
 * paid, and if not, why and how far behind is it?"
 *
 * States, from best to worst:
 *   - healthy:           paid up, nothing to do.
 *   - no_payment_method: paid up, but auto renew is on and the client has no
 *                        valid card, so the next renewal is going to fail.
 *   - retrying:          the renewal was declined, an automatic retry is
 *                        scheduled.
 *   - payment_pending:   still active, but one or more billing dates have
 *                        passed without a payment (manual billing that was
 *                        never charged, or a period that was never attempted).
 *   - payment_failed:    declined and no more automatic retries, an admin or
 *                        the client has to act.
 *   - inactive:          cancelled or expired, nothing is billed anymore.
 *
 * Nothing here is persisted: the state is derived from the subscription, its
 * charges and the client's saved cards every time, so it can never go stale.
 * Bound as scoped so list endpoints can prime the card lookup once.
 */
class SeoSubscriptionPaymentHealthService
{
    public const STATE_HEALTHY           = 'healthy';
    public const STATE_NO_PAYMENT_METHOD = 'no_payment_method';
    public const STATE_RETRYING          = 'retrying';
    public const STATE_PAYMENT_PENDING   = 'payment_pending';
    public const STATE_PAYMENT_FAILED    = 'payment_failed';
    public const STATE_INACTIVE          = 'inactive';

    public const SEVERITY_OK       = 'ok';
    public const SEVERITY_WARNING  = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    public const CARD_ISSUE_MISSING = 'missing';
    public const CARD_ISSUE_EXPIRED = 'expired';

    /**
     * Unpaid billing periods after which admins are nudged to consider
     * cancelling. The admin always decides, nothing is cancelled for them.
     */
    public const CANCELLATION_SUGGESTION_PERIODS = 2;

    /** @var array<int, array{usable: bool, count: int}> */
    private array $card_cache = [];

    public function __construct(
        protected InvoiceCardPaymentService $card_payment_service,
    ) {}

    /**
     * Loads the saved cards of many clients in one query, so a paginated
     * list does not run one query per row.
     */
    public function primeCardStatus(iterable $user_ids): void
    {
        $missing_ids = collect($user_ids)
            ->filter()
            ->unique()
            ->reject(fn ($user_id) => array_key_exists((int) $user_id, $this->card_cache))
            ->values();

        if ($missing_ids->isEmpty()) {
            return;
        }

        foreach ($missing_ids as $user_id) {
            $this->card_cache[(int) $user_id] = ['usable' => false, 'count' => 0];
        }

        PaymentProfile::whereIn('user_id', $missing_ids)
            ->get(['id', 'user_id', 'expiry_month', 'expiry_year'])
            ->each(function (PaymentProfile $profile) {
                $entry = &$this->card_cache[(int) $profile->user_id];
                $entry['count']++;

                if (! $this->card_payment_service->isPaymentProfileExpired($profile)) {
                    $entry['usable'] = true;
                }
            });
    }

    /** Clears the cached card lookup, after a card is added or removed. */
    public function forgetCardStatus(int $user_id): void
    {
        unset($this->card_cache[$user_id]);
    }

    /**
     * True when at least one non expired card is saved. Mirrors the billing
     * fallback: the selected card first, then any other valid card.
     */
    public function hasUsableCard(int $user_id): bool
    {
        return $this->cardStatus($user_id)['usable'];
    }

    public function evaluate(SeoPackageSubscription $subscription): array
    {
        $card_status = $this->cardStatus((int) $subscription->user_id);
        $card_issue  = $card_status['usable']
            ? null
            : ($card_status['count'] > 0 ? self::CARD_ISSUE_EXPIRED : self::CARD_ISSUE_MISSING);

        $health = [
            'state'                => self::STATE_HEALTHY,
            'severity'             => self::SEVERITY_OK,
            'overdue_periods'      => 0,
            'overdue_amount'       => 0.0,
            'oldest_due_date'      => null,
            'days_overdue'         => 0,
            'suggest_cancellation' => false,
            'has_usable_card'      => $card_status['usable'],
            'card_issue'           => $card_issue,
            'failure'              => null,
            'open_invoice'         => null,
        ];

        if (! $subscription->isActive()) {
            return ['state' => self::STATE_INACTIVE, 'severity' => self::SEVERITY_OK] + $health;
        }

        $charges        = $this->chargesOf($subscription);
        $next_date      = $subscription->next_billing_date;
        $today          = now()->startOfDay();
        $current_charge = $next_date
            ? $charges->first(fn (SeoSubscriptionCharge $charge) => $charge->period_start?->isSameDay($next_date))
            : null;

        $failed_charge = $current_charge && $current_charge->status === SeoSubscriptionCharge::STATUS_FAILED
            ? $current_charge
            : $charges->where('status', SeoSubscriptionCharge::STATUS_FAILED)->sortByDesc('period_start')->first();

        // Periods the schedule already moved past, but that were never paid
        // (an admin moved the billing date by hand after a failure).
        $earlier_unpaid = $next_date
            ? $charges->filter(fn (SeoSubscriptionCharge $charge) => $charge->status === SeoSubscriptionCharge::STATUS_FAILED
                && $charge->period_start?->lt($next_date))
            : collect();

        $overdue_dates = $subscription->overdueBillingDates($today);

        // A renewal declined today is owed even though its date is not past yet.
        if (count($overdue_dates) === 0 && $current_charge && $current_charge->status === SeoSubscriptionCharge::STATUS_FAILED) {
            $overdue_dates = [$next_date->copy()];
        }

        $overdue_amount = (float) $earlier_unpaid->sum('amount');

        foreach ($overdue_dates as $date) {
            $period_charge   = $charges->first(fn (SeoSubscriptionCharge $charge) => $charge->period_start?->isSameDay($date));
            $overdue_amount += (float) ($period_charge?->amount ?? $subscription->monthly_price);
        }

        $oldest_due = collect($overdue_dates)
            ->merge($earlier_unpaid->pluck('period_start'))
            ->filter()
            ->sort()
            ->first();

        $overdue_periods = count($overdue_dates) + $earlier_unpaid->count();

        $health['overdue_periods']      = $overdue_periods;
        $health['overdue_amount']       = round($overdue_amount, 2);
        $health['oldest_due_date']      = $oldest_due?->toDateString();
        $health['days_overdue']         = $oldest_due ? max(0, (int) $oldest_due->copy()->startOfDay()->diffInDays($today)) : 0;
        $health['suggest_cancellation'] = $overdue_periods >= self::CANCELLATION_SUGGESTION_PERIODS;
        $health['failure']              = $failed_charge ? $this->describeFailure($failed_charge) : null;
        $health['open_invoice']         = $this->openInvoiceOf($failed_charge ?? $current_charge);

        if ($failed_charge && $subscription->isPastDue()) {
            $health['state']    = $failed_charge->next_retry_at ? self::STATE_RETRYING : self::STATE_PAYMENT_FAILED;
            $health['severity'] = $failed_charge->next_retry_at && ! $health['suggest_cancellation']
                ? self::SEVERITY_WARNING
                : self::SEVERITY_CRITICAL;
        } elseif ($overdue_periods > 0) {
            $health['state']    = self::STATE_PAYMENT_PENDING;
            $health['severity'] = $health['suggest_cancellation'] ? self::SEVERITY_CRITICAL : self::SEVERITY_WARNING;
        } elseif ($subscription->auto_renew && ! $card_status['usable']) {
            $health['state']    = self::STATE_NO_PAYMENT_METHOD;
            $health['severity'] = self::SEVERITY_WARNING;
        }

        return $health;
    }

    /**
     * Client facing subset: no raw Stripe messages, no internal ids.
     */
    public function evaluateForClient(SeoPackageSubscription $subscription): array
    {
        $health  = $this->evaluate($subscription);
        $failure = $health['failure'];

        return [
            'state'           => $health['state'],
            'severity'        => $health['severity'],
            'overdue_periods' => $health['overdue_periods'],
            'overdue_amount'  => $health['overdue_amount'],
            'days_overdue'    => $health['days_overdue'],
            'has_usable_card' => $health['has_usable_card'],
            'card_issue'      => $health['card_issue'],
            'failure'         => $failure ? [
                'period_label'  => $failure['period_label'],
                'amount'        => $failure['amount'],
                'reason'        => $failure['client_reason'],
                'next_retry_at' => $failure['next_retry_at'],
            ] : null,
            'open_invoice'    => $health['open_invoice'] ? [
                'unique_id'      => $health['open_invoice']['unique_id'],
                'invoice_number' => $health['open_invoice']['invoice_number'],
            ] : null,
        ];
    }

    /**
     * Plain language reason shown to the client. The raw processor message
     * stays admin only.
     */
    public static function clientFacingFailureReason(?string $error_code): string
    {
        return match ($error_code) {
            'no_payment_method'                                             => 'There is no card on file for your account.',
            'expired_card'                                                  => 'The card on file has expired.',
            'authentication_required'                                       => 'Your bank asked for extra verification for this payment.',
            'insufficient_funds'                                            => 'Your bank declined the payment due to insufficient funds.',
            'lost_card', 'stolen_card', 'pickup_card', 'restricted_card'    => 'Your bank declined the card on file.',
            'incorrect_number', 'invalid_payment_method', 'invalid_account' => 'The card on file is no longer valid.',
            default                                                         => 'Your bank declined the payment.',
        };
    }

    /**
     * "2 months behind, $4,000.00 outstanding", used in admin emails.
     */
    public static function outstandingLabel(array $health): string
    {
        $periods = (int) ($health['overdue_periods'] ?? 0);

        if ($periods === 0) {
            return '';
        }

        $months = $periods === 1 ? '1 month' : "{$periods} months";

        return "{$months} behind, \$" . number_format((float) $health['overdue_amount'], 2) . ' outstanding';
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /** @return array{usable: bool, count: int} */
    private function cardStatus(int $user_id): array
    {
        if (! array_key_exists($user_id, $this->card_cache)) {
            $this->primeCardStatus([$user_id]);
        }

        return $this->card_cache[$user_id];
    }

    private function chargesOf(SeoPackageSubscription $subscription): Collection
    {
        if ($subscription->relationLoaded('charges')) {
            return $subscription->charges;
        }

        // Lists only eager load the latest period, which is the one that matters.
        if ($subscription->relationLoaded('latestCharge')) {
            return collect(array_filter([$subscription->latestCharge]));
        }

        return $subscription->charges()->with('invoice:id,unique_id,invoice_number,status')->get();
    }

    private function describeFailure(SeoSubscriptionCharge $charge): array
    {
        return [
            'charge_id'       => $charge->id,
            'period_label'    => $charge->periodLabel(),
            'period_start'    => $charge->period_start?->toDateString(),
            'amount'          => (float) $charge->amount,
            'code'            => $charge->failure_code,
            'message'         => $charge->failure_message,
            'client_reason'   => self::clientFacingFailureReason($charge->failure_code),
            'attempt_count'   => (int) $charge->attempt_count,
            'max_attempts'    => count(SeoSubscriptionBillingService::RETRY_SCHEDULE_DAYS) + 1,
            'last_attempt_at' => $charge->last_attempt_at?->toIso8601String(),
            'next_retry_at'   => $charge->next_retry_at?->toIso8601String(),
            'is_retryable'    => ! in_array($charge->failure_code, SeoSubscriptionBillingService::NON_RETRYABLE_ERROR_CODES, true),
            'card_label'      => $charge->card_label,
        ];
    }

    private function openInvoiceOf(?SeoSubscriptionCharge $charge): ?array
    {
        $invoice = $charge?->invoice_id ? $charge->invoice : null;

        if (! $invoice || ! in_array($invoice->status, ['unpaid', 'overdue'], true)) {
            return null;
        }

        return [
            'id'             => $invoice->id,
            'unique_id'      => $invoice->unique_id,
            'invoice_number' => $invoice->invoice_number,
            'status'         => $invoice->status,
        ];
    }
}
