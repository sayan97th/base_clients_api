<?php

namespace App\Jobs;

use App\Mail\AdminSeoSubscriptionBillingNotification;
use App\Models\SeoPackageSubscription;
use App\Services\EmailNotificationSettingService;
use App\Services\SeoSubscriptionPaymentHealthService;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Emails every admin recipient in Email Notification Settings when a client
 * removes the last valid card an auto renewing SEO subscription depends on,
 * so the team can follow up before the renewal fails.
 */
class SendAdminSeoSubscriptionPaymentMethodAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $max_exceptions = 2;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public int $subscription_id,
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $health_service = app(SeoSubscriptionPaymentHealthService::class);

        $subscription = SeoPackageSubscription::with(['user', 'package'])->find($this->subscription_id);
        $client       = $subscription?->user;

        // The client may have added a card again before the job ran.
        if (! $subscription || ! $client || ! $subscription->isActive() || ! $subscription->auto_renew) {
            return;
        }

        if ($health_service->hasUsableCard($client->id)) {
            return;
        }

        $client_name  = $client->full_name ?: $client->email;
        $billing_date = $subscription->next_billing_date ?? now()->startOfDay();
        $period_end   = $subscription->periodEndFor($billing_date);
        $renews_on    = $billing_date->format('F j, Y');
        $health       = $health_service->evaluate($subscription);

        foreach (EmailNotificationSettingService::resolveAdminRecipients() as $position => $recipient) {
            SendEmailJob::dispatchWithThrottle(
                new AdminSeoSubscriptionBillingNotification(
                    variant:           AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_METHOD_REMOVED,
                    recipient_name:    (string) $recipient['name'],
                    recipient_email:   $recipient['email'],
                    client_name:       $client_name,
                    client_email:      (string) $client->email,
                    client_company:    (string) ($client->company ?? ''),
                    client_initials:   $this->buildInitials($client_name),
                    plan_name:         $subscription->displayPlanName(),
                    is_custom_price:   $subscription->isCustomPricing(),
                    amount:            '$' . number_format($subscription->monthly_price, 2),
                    period_label:      $billing_date->format('F Y'),
                    period_range:      $billing_date->format('M j, Y') . ' to ' . $period_end->format('M j, Y'),
                    card_label:        'No card on file',
                    trigger_label:     'Card removed by the client',
                    attempt_label:     '',
                    invoice_number:    '',
                    failure_reason:    "The client has no valid card on file. The automatic renewal on {$renews_on} will fail unless they add a new card.",
                    next_retry_date:   '',
                    next_billing_date: $renews_on,
                    auto_renew:        true,
                    occurred_at:       now()->format('F j, Y \a\t g:i A'),
                    subscription_url:  FrontendUrl::to('/admin/seo-packages-subscriptions/' . $subscription->id),
                    invoice_url:       '',
                    settings_url:      FrontendUrl::to('/admin/email-notifications'),
                    outstanding_label: SeoSubscriptionPaymentHealthService::outstandingLabel($health),
                ),
                $recipient['email'],
                $position,
            );
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendAdminSeoSubscriptionPaymentMethodAlertJob failed', [
            'subscription_id' => $this->subscription_id,
            'error'           => $exception->getMessage(),
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
