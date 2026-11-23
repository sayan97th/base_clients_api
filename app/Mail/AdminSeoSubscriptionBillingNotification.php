<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the admin recipients in Email Notification Settings when an SEO
 * subscription payment goes through or fails, and when a client removes the
 * last card an auto renewing subscription depends on. The client gets their
 * own, client facing version (SeoSubscriptionBillingEmail).
 */
class AdminSeoSubscriptionBillingNotification extends Mailable
{
    use Queueable, SerializesModels;

    public const VARIANT_PAYMENT_SUCCEEDED = 'payment_succeeded';
    public const VARIANT_PAYMENT_FAILED    = 'payment_failed';
    public const VARIANT_PAYMENT_METHOD_REMOVED = 'payment_method_removed';

    public function __construct(
        public string $variant,
        public string $recipient_name,
        public string $recipient_email,
        public string $client_name,
        public string $client_email,
        public string $client_company,
        public string $client_initials,
        public string $plan_name,
        public bool   $is_custom_price,
        public string $amount,
        public string $period_label,
        public string $period_range,
        public string $card_label,
        public string $trigger_label,
        public string $attempt_label,
        public string $invoice_number,
        public string $failure_reason,
        public string $next_retry_date,
        public string $next_billing_date,
        public bool   $auto_renew,
        public string $occurred_at,
        public string $subscription_url,
        public string $invoice_url,
        public string $settings_url,
        /** "2 months behind, $4,000.00 outstanding", empty when nothing is owed. */
        public string $outstanding_label = '',
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        $app_name = config('app.name');

        $subject = match ($this->variant) {
            self::VARIANT_PAYMENT_SUCCEEDED      => "\u{2705} {$this->client_name} paid {$this->amount} for their SEO subscription ({$this->period_label}) | {$app_name}",
            self::VARIANT_PAYMENT_METHOD_REMOVED => "\u{1F4B3} {$this->client_name} removed their last card, the next SEO renewal will fail | {$app_name}",
            default                              => "\u{26A0}\u{FE0F} SEO subscription payment failed for {$this->client_name} ({$this->amount}) | {$app_name}",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-seo-subscription-billing-notification',
        );
    }
}
