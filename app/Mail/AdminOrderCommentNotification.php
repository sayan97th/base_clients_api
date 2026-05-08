<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminOrderCommentNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipient_name,
        public string $recipient_email,
        public string $client_name,
        public string $client_email,
        public string $client_initials,
        public string $order_id,
        public string $order_title,
        public string $comment_content,
        public string $comment_date,
        public string $view_comment_url,
        public string $settings_url,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Order Comment — ' . $this->order_title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-order-comment-notification',
        );
    }
}
