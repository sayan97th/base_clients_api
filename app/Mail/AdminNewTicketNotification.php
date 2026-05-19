<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminNewTicketNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipient_name,
        public string $recipient_email,
        public string $ticket_number,
        public string $ticket_subject,
        public string $ticket_priority,
        public string $client_name,
        public string $client_email,
        public string $client_initials,
        public string $initial_message,
        public string $ticket_date,
        public string $view_ticket_url,
        public string $settings_url,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Support Ticket — ' . $this->ticket_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-new-ticket-notification',
        );
    }
}
