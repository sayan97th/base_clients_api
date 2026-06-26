<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Client-facing notification confirming that a refund (or partial refund) has
 * been issued against one of their invoices. Pre-formatted, display-ready
 * strings are passed in so the Blade view stays free of formatting logic,
 * mirroring the AdminInvoicePaidNotification convention.
 */
class InvoiceRefundedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipient_name,
        public string $recipient_email,
        public string $invoice_number,
        public string $refund_amount,
        public string $total_amount,
        public string $total_refunded,
        public string $refund_date,
        public string $payment_method,
        public bool $is_full_refund,
        public ?string $credit_refund,
        public ?string $card_refund,
        public array $line_items,
        public string $invoice_url,
        public string $support_email,
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
            view: 'emails.invoice-refunded',
        );
    }
}
