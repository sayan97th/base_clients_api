<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketReplyClientNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $client_name,
        public string $client_email,
        public string $ticket_number,
        public string $ticket_subject,
        public string $admin_name,
        public string $admin_initials,
        public string $reply_content,
        public string $reply_date,
        public string $view_ticket_url,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your support ticket has a new reply — ' . $this->ticket_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket-reply-client-notification',
        );
    }
}
