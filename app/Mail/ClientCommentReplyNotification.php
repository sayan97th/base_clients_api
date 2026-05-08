<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientCommentReplyNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $client_name,
        public string $client_email,
        public string $order_id,
        public string $order_title,
        public string $original_comment_content,
        public string $original_comment_date,
        public string $reply_content,
        public string $reply_date,
        public string $admin_name,
        public string $admin_initials,
        public string $view_reply_url,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'The BASE team replied to your comment — ' . $this->order_title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.client-comment-reply-notification',
        );
    }
}
