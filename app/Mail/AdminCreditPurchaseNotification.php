<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminCreditPurchaseNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipient_name,
        public string $recipient_email,
        public string $client_name,
        public string $client_email,
        public string $client_initials,
        public string $package_name,
        public int    $credits_amount,
        public string $amount_paid,
        public string $purchase_date,
        public string $view_purchases_url,
        public string $settings_url,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Credit Purchase — ' . $this->client_name . ' - ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-credit-purchase-notification',
        );
    }
}
