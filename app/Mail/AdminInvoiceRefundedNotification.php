<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Admin-side alert sent to every recipient configured in the Email Notification
 * Settings whenever a refund (or partial refund) is issued on an invoice. Keeps
 * the finance/admin team in the loop on every money-out event.
 */
class AdminInvoiceRefundedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipient_name,
        public string $recipient_email,
        public string $invoice_number,
        public string $invoice_unique_id,
        public string $client_name,
        public string $client_email,
        public string $client_initials,
        public string $refund_amount,
        public string $total_amount,
        public string $total_refunded,
        public string $refund_date,
        public string $payment_method,
        public bool $is_full_refund,
        public ?string $credit_refund,
        public ?string $card_refund,
        public ?string $stripe_refund_id,
        public string $actor_name,
        public string $view_invoice_url,
        public string $settings_url,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        $label = $this->is_full_refund ? 'Refund' : 'Partial Refund';

        return new Envelope(
            subject: $label . ' Issued — Invoice ' . $this->invoice_number . ' - ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-invoice-refunded-notification',
        );
    }
}
