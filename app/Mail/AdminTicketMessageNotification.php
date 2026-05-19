<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminTicketMessageNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipient_name,
        public string $recipient_email,
        public string $ticket_number,
        public string $ticket_subject,
        public string $client_name,
        public string $client_email,
        public string $client_initials,
        public string $message_content,
        public string $message_date,
        public string $view_ticket_url,
        public string $settings_url,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Client replied to Support Ticket — ' . $this->ticket_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-ticket-message-notification',
        );
    }
}
