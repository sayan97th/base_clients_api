<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the client their automatic payment did not go through and how to fix
 * it. Sent synchronously (not queued) for the same reason as the admin alert.
 */
class AutopayChargeFailedClientNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $client_name,
        public string $client_email,
        public string $invoice_number,
        public string $amount,
        public string $card_label,
        public string $failure_reason,
        public ?string $next_retry_at,
        public bool $is_skipped,
        public string $pay_url,
        public string $manage_autopay_url,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->is_skipped
            ? "Action needed — Invoice {$this->invoice_number} was not paid automatically"
            : "Your automatic payment for invoice {$this->invoice_number} failed";

        return new Envelope(
            subject: $subject . ' - ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.autopay-charge-failed',
        );
    }
}
