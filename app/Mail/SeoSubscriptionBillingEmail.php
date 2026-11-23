<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Client emails for SEO subscription billing. One template, four moments:
 *   - payment_succeeded:      the monthly charge went through (receipt).
 *   - payment_failed:         the monthly charge was declined, action needed.
 *   - renewal_reminder:       heads up a few days before the card is charged.
 *   - payment_method_missing: the client removed their last card while the
 *                             subscription still renews automatically.
 */
class SeoSubscriptionBillingEmail extends Mailable
{
    use Queueable, SerializesModels;

    public const VARIANT_PAYMENT_SUCCEEDED = 'payment_succeeded';
    public const VARIANT_PAYMENT_FAILED    = 'payment_failed';
    public const VARIANT_RENEWAL_REMINDER  = 'renewal_reminder';
    public const VARIANT_PAYMENT_METHOD_MISSING = 'payment_method_missing';

    public function __construct(
        public string $variant,
        public string $client_first_name,
        public string $client_email,
        public string $plan_name,
        public string $amount,
        public string $period_label,
        public string $period_range,
        public string $billing_date,
        public string $next_billing_date,
        public string $card_brand,
        public string $card_label,
        public string $invoice_number,
        public string $invoice_url,
        public string $failure_reason,
        public string $next_retry_date,
        public string $paid_at,
        public string $billing_url,
        public string $support_url,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        $app_name = config('app.name');

        $subject = match ($this->variant) {
            self::VARIANT_PAYMENT_SUCCEEDED => "Payment received for your SEO subscription, {$this->period_label} | {$app_name}",
            self::VARIANT_PAYMENT_FAILED    => "Action needed: your SEO subscription payment did not go through | {$app_name}",
            self::VARIANT_PAYMENT_METHOD_MISSING => "Add a card to keep your SEO subscription running | {$app_name}",
            default                         => "Your SEO subscription renews on {$this->billing_date} | {$app_name}",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.seo-subscription-billing',
        );
    }
}
