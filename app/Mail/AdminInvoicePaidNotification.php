<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminInvoicePaidNotification extends Mailable
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
        public string $payment_date,
        public string $payment_method,
        public string $total_amount,
        public array  $line_items,
        public string $view_invoice_url,
        public string $settings_url,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment Received — Invoice ' . $this->invoice_number . ' - ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-invoice-paid-notification',
        );
    }
}
