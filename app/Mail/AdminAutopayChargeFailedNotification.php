<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to every recipient configured in Email Notification Settings when an
 * automatic (autopay) charge fails or is skipped.
 *
 * Intentionally NOT ShouldQueue: autopay failure alerts are sent synchronously
 * from the scheduler so they are still delivered when the queue workers
 * (supervisor) are down.
 */
class AdminAutopayChargeFailedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipient_name,
        public string $recipient_email,
        public string $invoice_number,
        public string $client_name,
        public string $client_email,
        public string $amount,
        public string $card_label,
        public string $attempt_label,
        public string $failure_reason,
        public ?string $failure_code,
        public ?string $next_retry_at,
        public bool $is_skipped,
        public string $view_invoice_url,
        public string $autopay_dashboard_url,
        public string $settings_url,
    ) {}

    public function envelope(): Envelope
    {
        $prefix = $this->is_skipped ? 'Autopay Skipped' : 'Autopay Payment Failed';

        return new Envelope(
            subject: "{$prefix} — Invoice {$this->invoice_number} - " . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-autopay-charge-failed',
        );
    }
}
