<?php

namespace App\Jobs;

use App\Mail\AdminSeoSubscriptionBillingNotification;
use App\Models\SeoSubscriptionCharge;
use App\Services\EmailNotificationSettingService;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Emails every admin recipient configured in Email Notification Settings
 * about an SEO subscription payment: collected, or failed.
 */
class SendAdminSeoSubscriptionBillingNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $max_exceptions = 2;

    public array $backoff = [30, 60, 120];

    private const TRIGGER_LABELS = [
        SeoSubscriptionCharge::TRIGGER_AUTOMATIC => 'Automatic renewal',
        SeoSubscriptionCharge::TRIGGER_MANUAL    => 'Charged by an admin',
        SeoSubscriptionCharge::TRIGGER_EXTERNAL  => 'Paid by the client',
    ];

    public function __construct(
        public int $charge_id,
        public string $variant,
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $charge = SeoSubscriptionCharge::with(['subscription.user', 'subscription.package', 'invoice', 'chargedBy'])
            ->find($this->charge_id);

        $subscription = $charge?->subscription;
        $client       = $subscription?->user;

        if (! $charge || ! $subscription || ! $client) {
            return;
        }

        $client_name  = $client->full_name ?: $client->email;
        $charged_by   = $charge->chargedBy ? ' (' . ($charge->chargedBy->full_name ?: $charge->chargedBy->email) . ')' : '';
        $invoice      = $charge->invoice;
        $is_succeeded = $this->variant === AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_SUCCEEDED;

        foreach (EmailNotificationSettingService::resolveAdminRecipients() as $position => $recipient) {
            SendEmailJob::dispatchWithThrottle(
                new AdminSeoSubscriptionBillingNotification(
                    variant:           $this->variant,
                    recipient_name:    (string) $recipient['name'],
                    recipient_email:   $recipient['email'],
                    client_name:       $client_name,
                    client_email:      (string) $client->email,
                    client_company:    (string) ($client->company ?? ''),
                    client_initials:   $this->buildInitials($client_name),
                    plan_name:         $subscription->displayPlanName(),
                    is_custom_price:   $subscription->isCustomPricing(),
                    amount:            '$' . number_format($charge->amount, 2),
                    period_label:      $charge->periodLabel(),
                    period_range:      $charge->period_start->format('M j, Y') . ' to ' . $charge->period_end->format('M j, Y'),
                    card_label:        (string) ($charge->card_label ?? 'No card on file'),
                    trigger_label:     (self::TRIGGER_LABELS[$charge->trigger] ?? 'Payment') . $charged_by,
                    attempt_label:     $charge->attempt_count > 1 ? "Attempt {$charge->attempt_count}" : 'First attempt',
                    invoice_number:    $invoice ? (string) $invoice->invoice_number : '',
                    failure_reason:    $is_succeeded ? '' : (string) ($charge->failure_message ?? 'The card was declined.'),
                    next_retry_date:   $is_succeeded ? '' : ($charge->next_retry_at?->format('F j, Y') ?? ''),
                    next_billing_date: $subscription->next_billing_date?->format('F j, Y') ?? '',
                    auto_renew:        (bool) $subscription->auto_renew,
                    occurred_at:       ($charge->paid_at ?? $charge->last_attempt_at ?? now())->format('F j, Y \a\t g:i A'),
                    subscription_url:  FrontendUrl::to('/admin/seo-packages-subscriptions/' . $subscription->id),
                    invoice_url:       $invoice ? FrontendUrl::to('/admin/invoices/' . $invoice->id) : '',
                    settings_url:      FrontendUrl::to('/admin/email-notifications'),
                ),
                $recipient['email'],
                $position,
            );
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendAdminSeoSubscriptionBillingNotificationJob failed', [
            'charge_id' => $this->charge_id,
            'variant'   => $this->variant,
            'error'     => $exception->getMessage(),
        ]);
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
